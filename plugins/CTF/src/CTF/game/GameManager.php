<?php
namespace CTF\game;

use CTF\Main;
use CTF\model\FlagState;
use CTF\model\Team;
use pocketmine\event\entity\EntityDamageByEntityEvent;
use pocketmine\item\Item;
use pocketmine\level\Position;
use pocketmine\level\particle\HappyVillagerParticle;
use pocketmine\level\particle\RedstoneParticle;
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

    /** @var string[] player=>team */
    private $teamOf = [];

    /** @var int[] team=>score */
    private $scores = [];

    /** @var int[] player=>respawnTicks */
    private $respawnQueue = [];

    public function __construct(Main $plugin){
        $this->plugin = $plugin;
        $this->loadArenaData();
    }

    public function getState(){ return $this->state; }

    public function isParticipant(Player $player){
        return isset($this->players[strtolower($player->getName())]);
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
        $player->sendMessage('§aJoined CTF queue.');

        $this->broadcast('§b' . $player->getName() . ' joined CTF (' . count($this->players) . ')');

        if($this->state === self::LOBBY && count($this->players) >= $this->plugin->getMinPlayers()){
            $this->state = self::COUNTDOWN;
            $this->countdown = $this->plugin->getCountdown();
            $this->broadcast('§6Countdown started.');
        }
    }

    public function leave(Player $player, $notify = true){
        $pn = strtolower($player->getName());
        if(!isset($this->players[$pn])){
            if($notify) $player->sendMessage('§cNot in CTF.');
            return;
        }

        $this->dropFlagIfCarrier($player, true);

        unset($this->players[$pn], $this->alive[$pn], $this->teamOf[$pn], $this->respawnQueue[$pn]);
        $this->plugin->getInventoryManager()->restore($player);

        if($notify) $player->sendMessage('§eYou left CTF.');

        if($this->state === self::IN_GAME){
            $this->checkWin();
        }
    }

    public function forceStart(){
        if($this->state === self::IN_GAME){
            return;
        }
        if(count($this->players) < $this->plugin->getMinPlayers()){
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
            if(count($this->players) < $this->plugin->getMinPlayers()){
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
            $this->updatePopup();
            $this->playAmbientParticles();
        }
    }

    public function onPlayerKilled(Player $player, Player $killer = null){
        $pn = strtolower($player->getName());
        if(!isset($this->players[$pn])) return;

        unset($this->alive[$pn]);
        $this->dropFlagIfCarrier($player, false);

        if($killer instanceof Player){
            $this->plugin->addStat($killer->getName(), 'kills', 1);
            $killer->sendMessage('§a+1 kill');
        }

        $ticks = $this->plugin->getRespawnDelay();
        $this->respawnQueue[$pn] = $ticks;
        $player->sendMessage('§cEliminated. Respawning in ' . $ticks . 's');
    }

    public function onHitNpcGuide(Player $player, EntityDamageByEntityEvent $event){
        $entity = $event->getEntity();
        $npcEntries = $this->plugin->getConfig()->getNested('npc.guides', []);
        foreach($npcEntries as $npc){
            if($entity->getLevel()->getName() !== $npc['level']) continue;
            $dx = $entity->x - (float) $npc['x'];
            $dy = $entity->y - (float) $npc['y'];
            $dz = $entity->z - (float) $npc['z'];
            if(sqrt($dx * $dx + $dy * $dy + $dz * $dz) <= 2.0){
                $event->setCancelled(true);
                $player->sendMessage('§6CTF Guide: §e/ctf join§7 | §e/ctf leave§7 | Capture enemy flag and return to your base.');
                return;
            }
        }
    }

    private function loadArenaData(){
        $this->teams = [];
        $this->flags = [];
        $this->scores = [];

        $teamConfig = (array) $this->plugin->getConfig()->get('teams', []);
        $level = $this->getArenaLevel();
        if($level === null){
            return;
        }

        foreach($teamConfig as $id => $row){
            $spawn = new Position((float) $row['spawn']['x'], (float) $row['spawn']['y'], (float) $row['spawn']['z'], $level);
            $team = new Team($id, $row['display'], $row['color'], $spawn);
            $this->teams[$id] = $team;
            $this->scores[$id] = 0;

            $flagPos = new Position((float) $row['flag']['x'], (float) $row['flag']['y'], (float) $row['flag']['z'], $level);
            $this->flags[$id] = new FlagState($team, $flagPos);
        }
    }

    private function startGame(){
        $this->state = self::IN_GAME;
        $this->assignTeams();

        foreach($this->players as $pn => $player){
            if(!$player instanceof Player || !$player->isOnline()) continue;
            $teamId = $this->teamOf[$pn];
            $player->teleport($this->teams[$teamId]->getSpawn());
            $player->setHealth($player->getMaxHealth());
            $player->getInventory()->clearAll();
            $player->getInventory()->addItem(Item::get(Item::WOODEN_SWORD, 0, 1));
            $player->getInventory()->addItem(Item::get(Item::BOW, 0, 1));
            $player->getInventory()->addItem(Item::get(Item::ARROW, 0, 8));
            $player->getInventory()->addItem(Item::get(Item::COOKED_BEEF, 0, 6));
            $this->equipTeamArmor($player, $teamId);
        }

        $this->broadcast('§aCTF Started! Capture the enemy flag and return it to your base.');
    }

    private function assignTeams(){
        if(count($this->teams) === 0){
            return;
        }

        $teamIds = array_keys($this->teams);
        $pool = array_values($this->players);
        shuffle($pool);

        $i = 0;
        foreach($pool as $player){
            $teamId = $teamIds[$i % count($teamIds)];
            $pn = strtolower($player->getName());
            $this->teamOf[$pn] = $teamId;
            $player->sendMessage('§7You are in team ' . $this->teams[$teamId]->getColoredName());
            $i++;
        }
    }

    private function tickRespawns(){
        foreach($this->respawnQueue as $pn => $seconds){
            $this->respawnQueue[$pn] = $seconds - 1;
            if($this->respawnQueue[$pn] > 0){
                continue;
            }

            unset($this->respawnQueue[$pn]);
            if(!isset($this->players[$pn]) || !isset($this->teamOf[$pn])) continue;

            $player = $this->players[$pn];
            if(!$player instanceof Player || !$player->isOnline()) continue;

            $teamId = $this->teamOf[$pn];
            $player->teleport($this->teams[$teamId]->getSpawn());
            $player->setHealth($player->getMaxHealth());
            $this->equipTeamArmor($player, $teamId);
            $this->alive[$pn] = $player;

            if($this->plugin->isParticlesEnabled()){
                $player->getLevel()->addParticle(new HappyVillagerParticle($player));
            }

            $player->sendMessage('§aRespawned. Protect your flag and capture enemy flag.');
        }
    }

    private function tickFlagInteractions(){
        foreach($this->players as $pn => $player){
            if(!isset($this->alive[$pn]) || !isset($this->teamOf[$pn])) continue;
            if(!$player instanceof Player || !$player->isOnline()) continue;

            $myTeam = $this->teamOf[$pn];

            foreach($this->flags as $teamId => $flag){
                if($teamId === $myTeam){
                    continue;
                }

                if($flag->getCarrier() !== null){
                    continue;
                }

                $distance = $player->distance($flag->getPosition());
                if($distance <= 2.0){
                    $flag->setCarrier($player);
                    $flag->setAtBase(false);
                    $this->broadcast($this->teams[$myTeam]->getColoredName() . ' §e' . $player->getName() . ' picked up ' . $flag->getTeam()->getColoredName() . ' §eflag!');
                }
            }

            // Return dropped own flag
            $ownFlag = $this->flags[$myTeam];
            if(!$ownFlag->isAtBase() && $ownFlag->getCarrier() === null && $player->distance($ownFlag->getPosition()) <= 2.0){
                $ownFlag->reset();
                $this->broadcast($this->teams[$myTeam]->getColoredName() . ' §eflag was returned by §a' . $player->getName());
            }

            // Capture logic: carrier reaches own base while own flag at base
            foreach($this->flags as $enemyTeam => $enemyFlag){
                if($enemyTeam === $myTeam) continue;
                if($enemyFlag->getCarrier() !== $player) continue;

                $homeFlag = $this->flags[$myTeam];
                if(!$homeFlag->isAtBase()){
                    $player->sendMessage('§cYour flag is not at base. You cannot score yet.');
                    continue;
                }

                if($player->distance($this->teams[$myTeam]->getSpawn()) <= 3.0){
                    $enemyFlag->reset();
                    $this->scores[$myTeam]++;
                    $this->broadcast($this->teams[$myTeam]->getColoredName() . ' §ascored! §7(' . $this->scores[$myTeam] . '/' . $this->plugin->getMaxScore() . ')');
                    $this->plugin->addStat($player->getName(), 'captures', 1);

                    if($this->plugin->isParticlesEnabled()){
                        $player->getLevel()->addParticle(new HappyVillagerParticle($player));
                    }

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

    private function dropFlagIfCarrier(Player $player, $returnToBase){
        foreach($this->flags as $flag){
            if($flag->getCarrier() === $player){
                if($returnToBase){
                    $flag->reset();
                }else{
                    $flag->setCarrier(null);
                    $flag->setAtBase(false);
                    $flag->setPosition(Position::fromObject($player, $player->getLevel()));
                    $this->broadcast($flag->getTeam()->getColoredName() . ' §eflag dropped at field!');
                }
            }
        }
    }

    private function endGame($winnerTeamId = null){
        $this->state = self::POST_GAME;

        if($winnerTeamId !== null && isset($this->teams[$winnerTeamId])){
            $team = $this->teams[$winnerTeamId];
            $this->broadcast('§aWinner: ' . $team->getColoredName());
            foreach($this->players as $pn => $player){
                if(isset($this->teamOf[$pn]) && $this->teamOf[$pn] === $winnerTeamId){
                    $this->plugin->addStat($player->getName(), 'wins', 1);
                }
            }
        }else{
            $this->broadcast('§cNo winner.');
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

        $this->players = [];
        $this->alive = [];
        $this->teamOf = [];
        $this->respawnQueue = [];
        foreach($this->scores as $team => $score){
            $this->scores[$team] = 0;
        }

        $this->state = self::LOBBY;
    }

    private function checkWin(){
        // Auto-win if only one team has online players.
        $onlineTeams = [];
        foreach($this->players as $pn => $player){
            if($player instanceof Player && $player->isOnline() && isset($this->teamOf[$pn])){
                $onlineTeams[$this->teamOf[$pn]] = true;
            }
        }
        if(count($onlineTeams) === 1){
            $this->endGame(array_keys($onlineTeams)[0]);
        }
    }

    private function updatePopup(){
        if(!$this->plugin->isPopupEnabled()) return;

        $scoreText = [];
        foreach($this->teams as $teamId => $team){
            $scoreText[] = $team->getColoredName() . '§f:' . $this->scores[$teamId];
        }

        $line = '§l§bCTF §r§7| ' . implode(' §7| ', $scoreText);
        foreach($this->players as $player){
            if($player instanceof Player && $player->isOnline()){
                $player->sendPopup($line);
            }
        }
    }

    private function playAmbientParticles(){
        if(!$this->plugin->isParticlesEnabled()) return;
        foreach($this->flags as $flag){
            $pos = $flag->getPosition();
            $pos->getLevel()->addParticle(new RedstoneParticle($pos));
        }
    }

    private function equipTeamArmor(Player $player, $teamId){
        $team = $this->teams[$teamId];
        $prefix = $team->getColoredName() . ' §fCTF';

        $inv = $player->getInventory();
        $inv->setHelmet(Item::get(Item::LEATHER_CAP, 0, 1)->setCustomName($prefix));
        $inv->setChestplate(Item::get(Item::LEATHER_TUNIC, 0, 1)->setCustomName($prefix));
        $inv->setLeggings(Item::get(Item::LEATHER_PANTS, 0, 1)->setCustomName($prefix));
        $inv->setBoots(Item::get(Item::LEATHER_BOOTS, 0, 1)->setCustomName($prefix));
    }

    public function getStatusLines(){
        $state = ['LOBBY', 'COUNTDOWN', 'IN_GAME', 'POST_GAME'][$this->state];
        $scores = [];
        foreach($this->teams as $id => $team){
            $scores[] = $team->getColoredName() . '§f=' . $this->scores[$id];
        }

        return [
            'State: ' . $state,
            'Players: ' . count($this->players),
            'Scores: ' . implode(', ', $scores)
        ];
    }

    private function broadcast($message){
        foreach($this->players as $player){
            if($player instanceof Player && $player->isOnline()){
                $player->sendMessage('§d[CTF] ' . $message);
            }
        }
        $this->plugin->getServer()->broadcastMessage('§d[CTF] ' . $message);
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
