<?php
namespace BedWars;

use pocketmine\block\Block;
use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\entity\Entity;
use pocketmine\event\block\BlockBreakEvent;
use pocketmine\event\entity\EntityDamageByEntityEvent;
use pocketmine\event\entity\EntityDamageEvent;
use pocketmine\event\Listener;
use pocketmine\event\player\PlayerDeathEvent;
use pocketmine\event\player\PlayerQuitEvent;
use pocketmine\item\Item;
use pocketmine\level\Position;
use pocketmine\level\particle\FlameParticle;
use pocketmine\level\particle\HappyVillagerParticle;
use pocketmine\nbt\tag\Compound;
use pocketmine\nbt\tag\DoubleTag;
use pocketmine\nbt\tag\FloatTag;
use pocketmine\nbt\tag\ListTag;
use pocketmine\plugin\PluginBase;
use pocketmine\Player;
use pocketmine\utils\Config;
use pocketmine\utils\TextFormat;

class Main extends PluginBase implements Listener{

    const WAITING = 0;
    const COUNTDOWN = 1;
    const RUNNING = 2;

    /** @var InventoryManager */
    private $inventoryManager;

    /** @var Config */
    private $stats;

    /** @var int */
    private $state = self::WAITING;
    /** @var int */
    private $countdown = 0;
    /** @var string */
    private $mode = 'solo';

    /** @var Player[] */
    private $queue = [];
    /** @var Player[] */
    private $players = [];
    /** @var Player[] */
    private $alive = [];

    /** @var string[] player=>team */
    private $teamOf = [];
    /** @var bool[] team=>bedAlive */
    private $bedAlive = [];

    public function onEnable(){
        @mkdir($this->getDataFolder());
        $this->saveDefaultConfig();
        $this->stats = new Config($this->getDataFolder() . 'stats.yml', Config::YAML, []);
        $this->inventoryManager = new InventoryManager();

        $this->getServer()->getPluginManager()->registerEvents($this, $this);
        $this->getServer()->getScheduler()->scheduleRepeatingTask(new PopupTask($this), 20);

        $this->spawnConfiguredShopNpcs();
        $this->getLogger()->info('BedWars enabled.');
    }

    public function onDisable(){
        foreach($this->players as $player){
            if($player instanceof Player && $player->isOnline()){
                $this->inventoryManager->restore($player);
            }
        }
    }

    public function onCommand(CommandSender $sender, Command $command, $label, array $args){
        if(strtolower($command->getName()) !== 'bw'){
            return false;
        }

        if(count($args) === 0){
            $sender->sendMessage('§e/bw <join|leave|start|shop|buy|top|setshop>');
            return true;
        }

        $sub = strtolower($args[0]);

        if($sub === 'join'){
            if(!($sender instanceof Player)) return true;
            $mode = isset($args[1]) ? strtolower($args[1]) : 'solo';
            if(!in_array($mode, ['solo', 'squad'], true)){
                $sender->sendMessage('§cUse: /bw join <solo|squad>');
                return true;
            }
            $this->joinQueue($sender, $mode);
            return true;
        }

        if($sub === 'leave'){
            if($sender instanceof Player){
                $this->leaveBedWars($sender, true);
            }
            return true;
        }

        if($sub === 'start'){
            if(!$sender->hasPermission('bedwars.admin')){
                $sender->sendMessage('§cNo permission');
                return true;
            }
            if($this->state === self::RUNNING){
                $sender->sendMessage('§cGame already running');
                return true;
            }
            $this->countdown = 5;
            $this->state = self::COUNTDOWN;
            $this->broadcastQueue('§6Force start in 5 seconds...');
            return true;
        }

        if($sub === 'setshop'){
            if(!($sender instanceof Player) || !$sender->hasPermission('bedwars.admin')) return true;
            $this->addShopNpc($sender);
            $sender->sendMessage('§aShop NPC added in config + spawned.');
            return true;
        }

        if($sub === 'shop'){
            if($sender instanceof Player){
                $this->showShopHelp($sender);
            }
            return true;
        }

        if($sub === 'buy'){
            if(!($sender instanceof Player)) return true;
            if(!isset($args[1])){
                $sender->sendMessage('§eUse: /bw buy <sword|blocks|armor|bow|arrows|gapple>');
                return true;
            }
            $this->buyItem($sender, strtolower($args[1]));
            return true;
        }

        if($sub === 'top'){
            $this->sendTop($sender);
            return true;
        }

        return true;
    }

    public function tick(){
        if($this->state === self::WAITING){
            if(count($this->queue) >= $this->minPlayers($this->mode)){
                $this->state = self::COUNTDOWN;
                $this->countdown = (int) $this->getConfig()->getNested('settings.countdown', 20);
                $this->broadcastQueue('§aCountdown started for §e' . strtoupper($this->mode));
            }
        }elseif($this->state === self::COUNTDOWN){
            if(count($this->queue) < $this->minPlayers($this->mode)){
                $this->state = self::WAITING;
                $this->broadcastQueue('§cCountdown canceled, not enough players');
            }else{
                if($this->countdown <= 0){
                    $this->startGame();
                }else{
                    if($this->countdown <= 5 || $this->countdown % 5 === 0){
                        $this->broadcastQueue('§6Starting in §e' . $this->countdown . '§6...');
                    }
                    $this->countdown--;
                }
            }
        }elseif($this->state === self::RUNNING){
            $this->renderPopup();
            $this->renderParticles();
            $this->checkWin();
        }
    }

    public function onQuit(PlayerQuitEvent $event){
        $this->leaveBedWars($event->getPlayer(), false);
    }

    public function onDeath(PlayerDeathEvent $event){
        $player = $event->getEntity();
        $pn = strtolower($player->getName());
        if(!isset($this->players[$pn])) return;

        $event->setKeepInventory(true);
        $event->setKeepExperience(true);
        $event->setDrops([]);

        $team = $this->teamOf[$pn];

        $cause = $player->getLastDamageCause();
        if($cause instanceof EntityDamageByEntityEvent && $cause->getDamager() instanceof Player){
            $killer = $cause->getDamager();
            $kName = strtolower($killer->getName());
            if(isset($this->players[$kName]) && isset($this->teamOf[$kName]) && $this->teamOf[$kName] !== $team){
                $this->addStat($killer->getName(), 'kills', 1);
                $killer->sendMessage('§a+1 kill');
            }
        }

        if(!$this->bedAlive[$team]){
            unset($this->alive[$pn]);
            $this->leaveBedWars($player, false);
            $player->sendMessage('§cYour bed is broken, you are eliminated.');
            $this->broadcastMatch('§c' . $player->getName() . ' eliminated.');
        }else{
            $spawn = $this->teamSpawn($team);
            $player->teleport($spawn);
            $this->applyTeamArmor($player, $team);
            $player->sendMessage('§aRespawned (bed alive).');
        }
    }

    public function onDamage(EntityDamageEvent $event){
        if($event instanceof EntityDamageByEntityEvent){
            $entity = $event->getEntity();
            $damager = $event->getDamager();
            if($entity instanceof Player && $damager instanceof Player){
                $a = strtolower($entity->getName());
                $b = strtolower($damager->getName());
                $inA = isset($this->players[$a]);
                $inB = isset($this->players[$b]);
                if($inA xor $inB){
                    $event->setCancelled(true);
                    return;
                }
                if($inA && $inB){
                    if($this->teamOf[$a] === $this->teamOf[$b]){
                        $event->setCancelled(true);
                        return;
                    }
                }
            }

            if($damager instanceof Player && !$damager->isCreative()){
                $this->handleNpcShopHit($damager, $event);
            }
        }
    }

    public function onBreak(BlockBreakEvent $event){
        $player = $event->getPlayer();
        $pn = strtolower($player->getName());
        if(!isset($this->players[$pn])) return;

        $block = $event->getBlock();
        if($block->getId() !== Block::BED_BLOCK) return;

        $team = $this->teamFromBed($block->x, $block->y, $block->z);
        if($team === null){
            return;
        }

        $myTeam = $this->teamOf[$pn];
        if($myTeam === $team){
            $player->sendMessage('§cYou cannot break your own bed.');
            $event->setCancelled(true);
            return;
        }

        if(!$this->bedAlive[$team]){
            $event->setCancelled(true);
            return;
        }

        $this->bedAlive[$team] = false;
        $this->broadcastMatch('§c' . $this->teamName($team) . ' bed has been broken by §e' . $player->getName());
    }

    private function joinQueue(Player $player, $mode){
        $pn = strtolower($player->getName());
        if(isset($this->players[$pn]) || isset($this->queue[$pn])){
            $player->sendMessage('§cYou are already in BedWars');
            return;
        }

        if($this->state !== self::WAITING && $mode !== $this->mode){
            $player->sendMessage('§cCurrent queue mode: ' . strtoupper($this->mode));
            return;
        }

        $this->mode = $mode;
        $this->queue[$pn] = $player;
        $this->inventoryManager->save($player);
        $player->getInventory()->clearAll();
        $player->teleport($this->lobbyPos());
        $player->sendMessage('§aJoined BedWars queue (' . strtoupper($mode) . ')');
        $this->broadcastQueue('§b' . $player->getName() . ' joined queue §7(' . count($this->queue) . ')');
    }

    private function leaveBedWars(Player $player, $message){
        $pn = strtolower($player->getName());

        if(isset($this->queue[$pn])){
            unset($this->queue[$pn]);
            $this->inventoryManager->restore($player);
            if($message) $player->sendMessage('§eYou left queue');
            return;
        }

        if(isset($this->players[$pn])){
            unset($this->players[$pn], $this->alive[$pn]);
            $this->inventoryManager->restore($player);
            if($message) $player->sendMessage('§eYou left BedWars');
            $this->checkWin();
        }
    }

    private function startGame(){
        $this->state = self::RUNNING;
        $this->players = $this->queue;
        $this->alive = $this->queue;
        $this->queue = [];

        $teams = array_keys((array) $this->getConfig()->getNested('arena.teams', []));
        foreach($teams as $team){
            $this->bedAlive[$team] = true;
        }

        $pool = array_values($this->players);
        shuffle($pool);

        $teamSize = (int) $this->getConfig()->getNested('arena.modes.' . $this->mode . '.teamSize', 1);
        $teamIndex = 0;
        $members = 0;
        foreach($pool as $player){
            $team = $teams[$teamIndex % count($teams)];
            $pn = strtolower($player->getName());
            $this->teamOf[$pn] = $team;

            $player->teleport($this->teamSpawn($team));
            $player->setHealth($player->getMaxHealth());
            $player->getInventory()->clearAll();
            $player->getInventory()->addItem(Item::get(Item::WOODEN_SWORD, 0, 1));
            $player->getInventory()->addItem(Item::get(Item::WOOL, $this->teamWoolMeta($team), 16));
            $this->applyTeamArmor($player, $team);

            $members++;
            if($members >= $teamSize){
                $members = 0;
                $teamIndex++;
            }
        }

        $this->broadcastMatch('§aBedWars started! Mode: §e' . strtoupper($this->mode));
        $this->broadcastMatch('§dHit Shop NPC or use §e/bw shop');
    }

    private function checkWin(){
        if($this->state !== self::RUNNING) return;

        $aliveTeams = [];
        foreach($this->alive as $pn => $player){
            if(!isset($this->teamOf[$pn])) continue;
            $aliveTeams[$this->teamOf[$pn]] = true;
        }

        if(count($aliveTeams) > 1) return;

        $winner = count($aliveTeams) === 1 ? array_keys($aliveTeams)[0] : null;
        if($winner !== null){
            $this->broadcastMatch('§aWinner team: ' . $this->teamName($winner));
            foreach($this->alive as $pn => $player){
                if(isset($this->teamOf[$pn]) && $this->teamOf[$pn] === $winner){
                    $this->addStat($player->getName(), 'wins', 1);
                }
            }
        }else{
            $this->broadcastMatch('§cNo winner this round.');
        }

        foreach($this->players as $pn => $player){
            if($player instanceof Player && $player->isOnline()){
                $this->inventoryManager->restore($player);
            }
        }

        $this->players = [];
        $this->alive = [];
        $this->teamOf = [];
        $this->bedAlive = [];
        $this->state = self::WAITING;
        $this->mode = 'solo';
    }

    private function showShopHelp(Player $player){
        $player->sendMessage('§6=== BedWars Shop ===');
        $player->sendMessage('§e/bw buy sword §7(4 iron)');
        $player->sendMessage('§e/bw buy blocks §7(8 iron)');
        $player->sendMessage('§e/bw buy armor §7(12 iron)');
        $player->sendMessage('§e/bw buy bow §7(10 gold)');
        $player->sendMessage('§e/bw buy arrows §7(2 gold)');
        $player->sendMessage('§e/bw buy gapple §7(5 gold)');
    }

    private function buyItem(Player $player, $name){
        $cost = [
            'sword' => ['id' => Item::IRON_INGOT, 'count' => 4],
            'blocks' => ['id' => Item::IRON_INGOT, 'count' => 8],
            'armor' => ['id' => Item::IRON_INGOT, 'count' => 12],
            'bow' => ['id' => Item::GOLD_INGOT, 'count' => 10],
            'arrows' => ['id' => Item::GOLD_INGOT, 'count' => 2],
            'gapple' => ['id' => Item::GOLD_INGOT, 'count' => 5],
        ];
        if(!isset($cost[$name])){
            $player->sendMessage('§cUnknown item.');
            return;
        }

        $c = $cost[$name];
        if(!$player->getInventory()->contains(Item::get($c['id'], 0, $c['count']))){
            $player->sendMessage('§cNot enough resources.');
            return;
        }

        $player->getInventory()->removeItem(Item::get($c['id'], 0, $c['count']));

        switch($name){
            case 'sword':
                $player->getInventory()->addItem(Item::get(Item::STONE_SWORD, 0, 1));
                break;
            case 'blocks':
                $team = $this->teamOf[strtolower($player->getName())] ?? 'red';
                $player->getInventory()->addItem(Item::get(Item::WOOL, $this->teamWoolMeta($team), 32));
                break;
            case 'armor':
                $team = $this->teamOf[strtolower($player->getName())] ?? 'red';
                $this->applyTeamArmor($player, $team, true);
                break;
            case 'bow':
                $player->getInventory()->addItem(Item::get(Item::BOW, 0, 1));
                break;
            case 'arrows':
                $player->getInventory()->addItem(Item::get(Item::ARROW, 0, 8));
                break;
            case 'gapple':
                $player->getInventory()->addItem(Item::get(Item::GOLDEN_APPLE, 0, 1));
                break;
        }

        $player->sendMessage('§aPurchased: §e' . $name);
    }

    private function applyTeamArmor(Player $player, $team, $upgraded = false){
        $prefix = $this->teamName($team) . ' §fArmor';

        $helmet = Item::get(Item::LEATHER_CAP, 0, 1)->setCustomName($prefix);
        $chest = Item::get(Item::LEATHER_TUNIC, 0, 1)->setCustomName($prefix);
        $legs = Item::get(Item::LEATHER_PANTS, 0, 1)->setCustomName($prefix);
        $boots = Item::get($upgraded ? Item::IRON_BOOTS : Item::LEATHER_BOOTS, 0, 1)->setCustomName($prefix);

        $inv = $player->getInventory();
        $inv->setHelmet($helmet);
        $inv->setChestplate($chest);
        $inv->setLeggings($legs);
        $inv->setBoots($boots);
    }

    private function renderPopup(){
        if(!$this->getConfig()->getNested('settings.popupEnabled', true)) return;
        foreach($this->players as $pn => $player){
            if(!$player instanceof Player || !$player->isOnline()) continue;
            $team = $this->teamOf[$pn] ?? '-';
            $beds = 0;
            foreach($this->bedAlive as $alive){
                if($alive) $beds++;
            }
            $txt = '§l§bBedWars §r§7| Team: ' . $this->teamName($team) . ' §7| Beds: §e' . $beds . ' §7| Alive: §a' . count($this->alive);
            $player->sendPopup($txt);
        }
    }

    private function renderParticles(){
        if(!$this->getConfig()->getNested('settings.particleEnabled', true)) return;

        $level = $this->arenaLevel();
        if($level === null) return;

        foreach($this->getShopNpcs() as $npc){
            $level->addParticle(new HappyVillagerParticle(new Position((float)$npc['x'], (float)$npc['y'] + 1.1, (float)$npc['z'], $level)));
        }

        foreach($this->bedAlive as $team => $alive){
            if(!$alive) continue;
            $bed = $this->getConfig()->getNested('arena.teams.' . $team . '.bed', []);
            if(isset($bed['x'], $bed['y'], $bed['z'])){
                $level->addParticle(new FlameParticle(new Position((float)$bed['x'], (float)$bed['y'] + 1, (float)$bed['z'], $level)));
            }
        }
    }

    private function sendTop(CommandSender $sender){
        $data = $this->stats->getAll();
        uasort($data, function($a, $b){
            return ($b['wins'] ?? 0) <=> ($a['wins'] ?? 0);
        });

        $sender->sendMessage('§6=== BedWars Top Wins ===');
        $i = 1;
        foreach($data as $name => $row){
            $sender->sendMessage('§e#' . $i . ' §f' . $name . ' §7- §a' . ((int)($row['wins'] ?? 0)) . ' wins');
            $i++;
            if($i > 10) break;
        }
    }

    private function handleNpcShopHit(Player $damager, EntityDamageByEntityEvent $event){
        $entity = $event->getEntity();
        foreach($this->getShopNpcs() as $npc){
            if($entity->getLevel()->getName() !== $npc['level']) continue;
            $dx = $entity->x - (float)$npc['x'];
            $dy = $entity->y - (float)$npc['y'];
            $dz = $entity->z - (float)$npc['z'];
            $dist = sqrt($dx*$dx + $dy*$dy + $dz*$dz);
            if($dist <= 2.0){
                $event->setCancelled(true);
                $damager->sendMessage('§d[NPC Shop] Welcome! Use §e/bw shop');
                return;
            }
        }
    }

    private function spawnConfiguredShopNpcs(){
        foreach($this->getShopNpcs() as $npc){
            $level = $this->getServer()->getLevelByName($npc['level']);
            if($level === null){
                $this->getServer()->loadLevel($npc['level']);
                $level = $this->getServer()->getLevelByName($npc['level']);
            }
            if($level === null) continue;

            $nbt = new Compound('', [
                'Pos' => new ListTag('Pos', [
                    new DoubleTag('', (float)$npc['x']),
                    new DoubleTag('', (float)$npc['y']),
                    new DoubleTag('', (float)$npc['z'])
                ]),
                'Motion' => new ListTag('Motion', [new DoubleTag('', 0), new DoubleTag('', 0), new DoubleTag('', 0)]),
                'Rotation' => new ListTag('Rotation', [new FloatTag('', 0), new FloatTag('', 0)])
            ]);

            $villager = Entity::createEntity('Villager', $level->getChunk((int)$npc['x'] >> 4, (int)$npc['z'] >> 4), $nbt);
            if($villager !== null){
                $villager->setNameTagAlwaysVisible(true);
                $villager->setNameTag('§d' . ($npc['name'] ?? 'Shop NPC'));
                $villager->spawnToAll();
            }
        }
    }

    private function addShopNpc(Player $player){
        $cfg = $this->getConfig()->get('shop');
        if(!isset($cfg['npcs']) || !is_array($cfg['npcs'])) $cfg['npcs'] = [];

        $cfg['npcs'][] = [
            'level' => $player->getLevel()->getName(),
            'x' => round($player->x, 2),
            'y' => round($player->y, 2),
            'z' => round($player->z, 2),
            'name' => 'Shop'
        ];

        $this->getConfig()->set('shop', $cfg);
        $this->getConfig()->save();

        $this->spawnConfiguredShopNpcs();
    }

    private function getShopNpcs(){
        return (array) $this->getConfig()->getNested('shop.npcs', []);
    }

    private function teamSpawn($team){
        $s = (array) $this->getConfig()->getNested('arena.teams.' . $team . '.spawn', []);
        return new Position((float)$s['x'], (float)$s['y'], (float)$s['z'], $this->arenaLevel());
    }

    private function lobbyPos(){
        $l = (array) $this->getConfig()->getNested('arena.lobby', []);
        return new Position((float)$l['x'], (float)$l['y'], (float)$l['z'], $this->arenaLevel());
    }

    private function arenaLevel(){
        $name = (string) $this->getConfig()->getNested('arena.level', 'world');
        $level = $this->getServer()->getLevelByName($name);
        if($level === null){
            $this->getServer()->loadLevel($name);
            $level = $this->getServer()->getLevelByName($name);
        }
        return $level;
    }

    private function teamName($team){
        if($team === null || $team === '-') return '§7-';
        $color = (string) $this->getConfig()->getNested('arena.teams.' . $team . '.color', '§f');
        return $color . ucfirst($team);
    }

    private function teamWoolMeta($team){
        $map = ['red' => 14, 'blue' => 11, 'green' => 13, 'yellow' => 4];
        return $map[$team] ?? 0;
    }

    private function teamFromBed($x, $y, $z){
        $teams = (array) $this->getConfig()->getNested('arena.teams', []);
        foreach($teams as $name => $row){
            if(!isset($row['bed'])) continue;
            $b = $row['bed'];
            if((int)$b['x'] === (int)$x && (int)$b['y'] === (int)$y && (int)$b['z'] === (int)$z){
                return $name;
            }
        }
        return null;
    }

    private function minPlayers($mode){
        if($mode === 'squad') return (int) $this->getConfig()->getNested('settings.minPlayersSquad', 4);
        return (int) $this->getConfig()->getNested('settings.minPlayersSolo', 2);
    }

    private function broadcastQueue($msg){
        foreach($this->queue as $p){
            if($p instanceof Player && $p->isOnline()) $p->sendMessage('§d[BedWars] ' . $msg);
        }
    }

    private function broadcastMatch($msg){
        foreach($this->players as $p){
            if($p instanceof Player && $p->isOnline()) $p->sendMessage('§d[BedWars] ' . $msg);
        }
        $this->getServer()->broadcastMessage('§d[BedWars] ' . $msg);
    }

    private function addStat($name, $key, $value){
        $row = (array) $this->stats->get(strtolower($name), ['wins' => 0, 'kills' => 0]);
        $row[$key] = ((int)($row[$key] ?? 0)) + (int)$value;
        $this->stats->set(strtolower($name), $row);
        $this->stats->save();
    }
}
