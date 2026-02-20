<?php
namespace CTF\game;

use CTF\Main;
use CTF\model\FlagState;
use CTF\model\Team;
use pocketmine\entity\Entity;
use pocketmine\event\entity\EntityDamageByEntityEvent;
use pocketmine\item\Item;
use pocketmine\level\Position;
use pocketmine\level\particle\HappyVillagerParticle;
use pocketmine\level\particle\RedstoneParticle;
use pocketmine\nbt\tag\Compound;
use pocketmine\nbt\tag\DoubleTag;
use pocketmine\nbt\tag\FloatTag;
use pocketmine\nbt\tag\ListTag;
use pocketmine\Player;

class GameManager{

    const LOBBY = 0;
    const COUNTDOWN = 1;
    const IN_GAME = 2;
    const POST_GAME = 3;

    /** @var Main */
    private $plugin;
    /** @var int */
    private $state = self::LOBBY;
    /** @var int */
    private $countdown = 0;

    /** @var Team[] */
    private $teams = [];
    /** @var FlagState[] */
    private $flags = [];
    /** @var Player[] */
    private $players = [];
    /** @var Player[] */
    private $alive = [];
    /** @var string[] */
    private $teamOf = [];
    /** @var int[] */
    private $scores = [];
    /** @var int[] */
    private $respawnQueue = [];

    /** @var bool */
    private $botMode = false;
    /** @var string */
    private $botName = 'CTF-Bot';
    /** @var string|null */
    private $botTeamId = null;
    /** @var int */
    private $botTickCounter = 0;
    /** @var Entity|null */
    private $botEntity = null;

    public function __construct(Main $plugin){
        $this->plugin = $plugin;
        $this->loadArenaData();
    }

    public function isParticipant(Player $player){
        return isset($this->players[strtolower($player->getName())]);
    }

    public function joinWithBot(Player $player){
        if(count($this->teams) < 2){
            $player->sendMessage('§cNeed at least 2 teams in CTF config.');
            return;
        }

        $this->botMode = true;
        $this->join($player);

        if($this->state === self::LOBBY){
            $this->state = self::COUNTDOWN;
            $this->countdown = 5;
        }

        $this->broadcast('§eBot mode enabled. Enemy bot will spawn at match start.');
    }

    public function join(Player $player){
        $pn = strtolower($player->getName());
        if(isset($this->players[$pn])){
            $player->sendMessage('§cYou are already in CTF.');
            return;
        }

        $this->players[$pn] = $player;
        $this->alive[$pn] = $player;
        $this->plugin->getInventoryManager()->save($player);
        $player->getInventory()->clearAll();
        $player->teleport($this->getLobbyPosition());

        $this->broadcast('§b' . $player->getName() . ' joined CTF (' . count($this->players) . ')');

        if($this->state === self::LOBBY && $this->getActiveCompetitorCount() >= $this->plugin->getMinPlayers()){
            $this->state = self::COUNTDOWN;
            $this->countdown = $this->plugin->getCountdown();
            $this->broadcast('§6Countdown started.');
        }
    }

    public function leave(Player $player, $notify = true){
        $pn = strtolower($player->getName());
        if(!isset($this->players[$pn])){
            if($notify){
                $player->sendMessage('§cNot in CTF.');
            }
            return;
        }

        $this->dropFlagIfCarrier($player, true);
        unset($this->players[$pn], $this->alive[$pn], $this->teamOf[$pn], $this->respawnQueue[$pn]);

        $this->plugin->getInventoryManager()->restore($player);
        if($notify){
            $player->sendMessage('§eYou left CTF.');
        }

        if($this->state === self::IN_GAME){
            $this->checkWin();
        }
    }

    public function forceStart(){
        if($this->state === self::IN_GAME){
            return;
        }
        if($this->getActiveCompetitorCount() < $this->plugin->getMinPlayers() && !$this->botMode){
            $this->broadcast('§cNot enough players.');
            return;
        }

        $this->state = self::COUNTDOWN;
        $this->countdown = 5;
        $this->broadcast('§6Forced start in 5 seconds.');
    }

    public function stop(){
        $this->broadcast('§cCTF stopped by admin.');
        $this->endGame(null);
    }

    public function tick(){
        if($this->state === self::COUNTDOWN){
            if($this->getActiveCompetitorCount() < $this->plugin->getMinPlayers() && !$this->botMode){
                $this->state = self::LOBBY;
                $this->broadcast('§cCountdown canceled: insufficient players.');
                return;
            }

            if($this->countdown <= 0){
                $this->startGame();
                return;
            }

            if($this->countdown <= 5 || $this->countdown % 5 === 0){
                $this->broadcast('§eStarting in ' . $this->countdown . '...');
            }
            $this->countdown--;
        }

        if($this->state === self::IN_GAME){
            $this->tickRespawns();
            $this->tickFlagInteractions();
            $this->tickBotMode();
            $this->updatePopup();
            $this->playAmbientParticles();
        }
    }

    public function onPlayerKilled(Player $player, Player $killer = null){
        $pn = strtolower($player->getName());
        if(!isset($this->players[$pn])){
            return;
        }

        unset($this->alive[$pn]);
        $this->dropFlagIfCarrier($player, false);

        if($killer instanceof Player){
            $this->plugin->addStat($killer->getName(), 'kills', 1);
        }

        $this->respawnQueue[$pn] = $this->plugin->getRespawnDelay();
    }

    public function onHitNpcGuide(Player $player, EntityDamageByEntityEvent $event){
        $entity = $event->getEntity();
        foreach((array) $this->plugin->getConfig()->getNested('npc.guides', []) as $npc){
            if(!isset($npc['level'], $npc['x'], $npc['y'], $npc['z'])){
                continue;
            }
            if($entity->getLevel()->getName() !== $npc['level']){
                continue;
            }

            $dx = $entity->x - (float) $npc['x'];
            $dy = $entity->y - (float) $npc['y'];
            $dz = $entity->z - (float) $npc['z'];
            if(sqrt($dx * $dx + $dy * $dy + $dz * $dz) <= 2.0){
                $event->setCancelled(true);
                $player->sendMessage('§6CTF Guide: §e/ctf join§7, §e/ctf join bot§7, §e/ctf leave');
                return;
            }
        }
    }

    public function getStatusLines(){
        $state = ['LOBBY', 'COUNTDOWN', 'IN_GAME', 'POST_GAME'][$this->state];
        $scores = [];
        foreach($this->teams as $id => $team){
            $scores[] = $team->getColoredName() . '§f=' . $this->scores[$id];
        }

        return [
            'State: ' . $state,
            'Players: ' . count($this->players) . ($this->botMode ? ' + BOT' : ''),
            'Scores: ' . implode(', ', $scores)
        ];
    }

    private function loadArenaData(){
        $this->teams = [];
        $this->flags = [];
        $this->scores = [];

        $level = $this->getArenaLevel();
        if($level === null){
            return;
        }

        foreach((array) $this->plugin->getConfig()->get('teams', []) as $id => $row){
            if(!isset($row['display'], $row['color'], $row['spawn'], $row['flag'])){
                continue;
            }
            $spawn = new Position((float) $row['spawn']['x'], (float) $row['spawn']['y'], (float) $row['spawn']['z'], $level);
            $flagPos = new Position((float) $row['flag']['x'], (float) $row['flag']['y'], (float) $row['flag']['z'], $level);

            $team = new Team($id, $row['display'], $row['color'], $spawn);
            $this->teams[$id] = $team;
            $this->flags[$id] = new FlagState($team, $flagPos);
            $this->scores[$id] = 0;
        }
    }

    private function startGame(){
        $this->state = self::IN_GAME;
        $this->assignTeams();

        foreach($this->players as $pn => $player){
            if(!$player instanceof Player || !$player->isOnline() || !isset($this->teamOf[$pn])){
                continue;
            }
            $teamId = $this->teamOf[$pn];
            $player->teleport($this->teams[$teamId]->getSpawn());
            $player->setHealth($player->getMaxHealth());
            $player->getInventory()->clearAll();
            $player->getInventory()->addItem(Item::get(Item::WOODEN_SWORD, 0, 1));
            $player->getInventory()->addItem(Item::get(Item::BOW, 0, 1));
            $player->getInventory()->addItem(Item::get(Item::ARROW, 0, 12));
            $player->getInventory()->addItem(Item::get(Item::COOKED_BEEF, 0, 8));
            $this->equipTeamArmor($player, $teamId);
        }

        if($this->botMode && $this->botTeamId !== null){
            $this->spawnBotEntity();
        }

        $this->broadcast('§aCTF started! Capture enemy flag and return to your base.');
    }

    private function assignTeams(){
        $teamIds = array_keys($this->teams);
        if(count($teamIds) < 2){
            return;
        }

        if($this->botMode && count($this->players) === 1){
            $player = array_values($this->players)[0];
            $pn = strtolower($player->getName());
            $this->teamOf[$pn] = $teamIds[0];
            $this->botTeamId = $teamIds[1];
            $player->sendMessage('§7Your team: ' . $this->teams[$teamIds[0]]->getColoredName());
            return;
        }

        $pool = array_values($this->players);
        shuffle($pool);
        $i = 0;
        foreach($pool as $player){
            $teamId = $teamIds[$i % count($teamIds)];
            $this->teamOf[strtolower($player->getName())] = $teamId;
            $i++;
        }
    }

    private function tickRespawns(){
        foreach($this->respawnQueue as $pn => $left){
            $this->respawnQueue[$pn] = $left - 1;
            if($this->respawnQueue[$pn] > 0){
                continue;
            }
            unset($this->respawnQueue[$pn]);

            if(!isset($this->players[$pn], $this->teamOf[$pn])){
                continue;
            }

            $player = $this->players[$pn];
            if(!$player instanceof Player || !$player->isOnline()){
                continue;
            }

            $teamId = $this->teamOf[$pn];
            $player->teleport($this->teams[$teamId]->getSpawn());
            $player->setHealth($player->getMaxHealth());
            $this->equipTeamArmor($player, $teamId);
            $this->alive[$pn] = $player;

            if($this->plugin->isParticlesEnabled()){
                $player->getLevel()->addParticle(new HappyVillagerParticle($player));
            }
        }
    }

    private function tickFlagInteractions(){
        foreach($this->players as $pn => $player){
            if(!$player instanceof Player || !$player->isOnline()){
                continue;
            }
            if(!isset($this->alive[$pn], $this->teamOf[$pn])){
                continue;
            }

            $myTeam = $this->teamOf[$pn];

            foreach($this->flags as $teamId => $flag){
                if($teamId === $myTeam || $flag->getCarrier() !== null){
                    continue;
                }
                if($player->distance($flag->getPosition()) <= 2.0){
                    $flag->setCarrier($player);
                    $flag->setAtBase(false);
                    $this->broadcast($this->teams[$myTeam]->getColoredName() . ' §e' . $player->getName() . ' took ' . $flag->getTeam()->getColoredName() . ' §eflag!');
                }
            }

            $ownFlag = $this->flags[$myTeam];
            if(!$ownFlag->isAtBase() && $ownFlag->getCarrier() === null && $player->distance($ownFlag->getPosition()) <= 2.0){
                $ownFlag->reset();
                $this->broadcast($this->teams[$myTeam]->getColoredName() . ' §eflag returned.');
            }

            foreach($this->flags as $enemyTeam => $enemyFlag){
                if($enemyTeam === $myTeam || $enemyFlag->getCarrier() !== $player){
                    continue;
                }

                if(!$this->flags[$myTeam]->isAtBase()){
                    continue;
                }

                if($player->distance($this->teams[$myTeam]->getSpawn()) <= 3.0){
                    $enemyFlag->reset();
                    $this->scores[$myTeam]++;
                    $this->plugin->addStat($player->getName(), 'captures', 1);
                    $this->broadcast($this->teams[$myTeam]->getColoredName() . ' §ascored! §7(' . $this->scores[$myTeam] . '/' . $this->plugin->getMaxScore() . ')');

                    if($this->scores[$myTeam] >= $this->plugin->getMaxScore()){
                        $this->endGame($myTeam);
                        return;
                    }
                }
            }
        }

        foreach($this->flags as $flag){
            $carrier = $flag->getCarrier();
            if($carrier instanceof Player && $carrier->isOnline()){
                $flag->setPosition(Position::fromObject($carrier, $carrier->getLevel()));
            }
        }
    }

    private function tickBotMode(){
        if(!$this->botMode || $this->botTeamId === null || $this->state !== self::IN_GAME){
            return;
        }

        $this->botTickCounter++;
        if($this->botTickCounter % 10 === 0 && $this->botEntity instanceof Entity && !$this->botEntity->closed){
            $spawn = $this->teams[$this->botTeamId]->getSpawn();
            $this->botEntity->teleport(new Position($spawn->x + mt_rand(-6, 6), $spawn->y, $spawn->z + mt_rand(-6, 6), $spawn->getLevel()));
        }

        if($this->botTickCounter < 20){
            return;
        }
        $this->botTickCounter = 0;

        $enemyTeams = [];
        foreach($this->scores as $teamId => $s){
            if($teamId !== $this->botTeamId){
                $enemyTeams[] = $teamId;
            }
        }
        if(count($enemyTeams) === 0){
            return;
        }

        $this->scores[$this->botTeamId]++;
        $target = $enemyTeams[array_rand($enemyTeams)];
        $this->broadcast('§c' . $this->botName . ' §escored against ' . $this->teams[$target]->getColoredName() . '§e!');

        if($this->plugin->isParticlesEnabled() && $this->botEntity instanceof Entity && !$this->botEntity->closed){
            $this->botEntity->getLevel()->addParticle(new HappyVillagerParticle($this->botEntity));
        }

        if($this->scores[$this->botTeamId] >= $this->plugin->getMaxScore()){
            $this->endGame($this->botTeamId);
        }
    }

    private function spawnBotEntity(){
        $this->despawnBotEntity();

        if($this->botTeamId === null || !isset($this->teams[$this->botTeamId])){
            return;
        }

        $pos = $this->teams[$this->botTeamId]->getSpawn();
        $level = $pos->getLevel();

        $nbt = new Compound('', [
            'Pos' => new ListTag('Pos', [new DoubleTag('', (float) $pos->x), new DoubleTag('', (float) $pos->y), new DoubleTag('', (float) $pos->z)]),
            'Motion' => new ListTag('Motion', [new DoubleTag('', 0.0), new DoubleTag('', 0.0), new DoubleTag('', 0.0)]),
            'Rotation' => new ListTag('Rotation', [new FloatTag('', 0.0), new FloatTag('', 0.0)])
        ]);

        $entity = Entity::createEntity('Villager', $level->getChunk((int) $pos->x >> 4, (int) $pos->z >> 4), $nbt);
        if($entity instanceof Entity){
            $entity->setNameTagAlwaysVisible(true);
            $entity->setNameTag('§c' . $this->botName);
            $entity->spawnToAll();
            $this->botEntity = $entity;
        }
    }

    private function despawnBotEntity(){
        if($this->botEntity instanceof Entity && !$this->botEntity->closed){
            $this->botEntity->close();
        }
        $this->botEntity = null;
    }

    private function dropFlagIfCarrier(Player $player, $returnToBase){
        foreach($this->flags as $flag){
            if($flag->getCarrier() !== $player){
                continue;
            }
            if($returnToBase){
                $flag->reset();
            }else{
                $flag->setCarrier(null);
                $flag->setAtBase(false);
                $flag->setPosition(Position::fromObject($player, $player->getLevel()));
            }
        }
    }

    private function checkWin(){
        $onlineTeams = [];
        foreach($this->players as $pn => $player){
            if($player instanceof Player && $player->isOnline() && isset($this->teamOf[$pn])){
                $onlineTeams[$this->teamOf[$pn]] = true;
            }
        }
        if($this->botMode && $this->botTeamId !== null){
            $onlineTeams[$this->botTeamId] = true;
        }

        if(count($onlineTeams) === 1){
            $this->endGame(array_keys($onlineTeams)[0]);
        }
    }

    private function endGame($winnerTeamId = null){
        $this->state = self::POST_GAME;

        if($winnerTeamId !== null && isset($this->teams[$winnerTeamId])){
            $winner = $this->teams[$winnerTeamId];
            $this->broadcast('§aWinner: ' . $winner->getColoredName());
            foreach($this->players as $pn => $player){
                if(isset($this->teamOf[$pn]) && $this->teamOf[$pn] === $winnerTeamId){
                    $this->plugin->addStat($player->getName(), 'wins', 1);
                }
            }
        }

        foreach($this->players as $player){
            if($player instanceof Player && $player->isOnline()){
                $this->plugin->getInventoryManager()->restore($player);
                $player->teleport($this->getLobbyPosition());
            }
        }

        foreach($this->flags as $flag){
            $flag->reset();
        }

        $this->despawnBotEntity();
        $this->players = [];
        $this->alive = [];
        $this->teamOf = [];
        $this->respawnQueue = [];
        foreach($this->scores as $teamId => $score){
            $this->scores[$teamId] = 0;
        }
        $this->botMode = false;
        $this->botTeamId = null;
        $this->botTickCounter = 0;
        $this->state = self::LOBBY;
    }

    private function updatePopup(){
        if(!$this->plugin->isPopupEnabled()){
            return;
        }
        $rows = [];
        foreach($this->teams as $teamId => $team){
            $rows[] = $team->getColoredName() . '§f:' . $this->scores[$teamId];
        }
        $line = '§l§bCTF §r§7| ' . implode(' §7| ', $rows);
        foreach($this->players as $player){
            if($player instanceof Player && $player->isOnline()){
                $player->sendPopup($line);
            }
        }
    }

    private function playAmbientParticles(){
        if(!$this->plugin->isParticlesEnabled()){
            return;
        }
        foreach($this->flags as $flag){
            $pos = $flag->getPosition();
            if($pos instanceof Position && $pos->getLevel() !== null){
                $pos->getLevel()->addParticle(new RedstoneParticle($pos));
            }
        }
    }

    private function equipTeamArmor(Player $player, $teamId){
        if(!isset($this->teams[$teamId])) return;
        $prefix = $this->teams[$teamId]->getColoredName() . ' §fCTF';
        $inv = $player->getInventory();
        $inv->setHelmet(Item::get(Item::LEATHER_CAP, 0, 1)->setCustomName($prefix));
        $inv->setChestplate(Item::get(Item::LEATHER_TUNIC, 0, 1)->setCustomName($prefix));
        $inv->setLeggings(Item::get(Item::LEATHER_PANTS, 0, 1)->setCustomName($prefix));
        $inv->setBoots(Item::get(Item::LEATHER_BOOTS, 0, 1)->setCustomName($prefix));
    }

    private function getActiveCompetitorCount(){
        return count($this->players) + ($this->botMode ? 1 : 0);
    }

    private function broadcast($message){
        foreach($this->players as $player){
            if($player instanceof Player && $player->isOnline()){
                $player->sendMessage('§d[CTF] ' . $message);
            }
        }
    }

    private function getLobbyPosition(){
        $level = $this->getArenaLevel();
        $lobby = (array) $this->plugin->getConfig()->getNested('arena.lobby', ['x' => 128, 'y' => 70, 'z' => 128]);
        return new Position((float) $lobby['x'], (float) $lobby['y'], (float) $lobby['z'], $level);
    }

    private function getArenaLevel(){
        $name = (string) $this->plugin->getConfig()->getNested('arena.level', 'world');
        $level = $this->plugin->getServer()->getLevelByName($name);
        if($level === null){
            $this->plugin->getServer()->loadLevel($name);
            $level = $this->plugin->getServer()->getLevelByName($name);
        }
        return $level;
    }
}
