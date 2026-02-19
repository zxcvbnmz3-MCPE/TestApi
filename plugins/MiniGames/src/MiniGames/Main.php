<?php
namespace MiniGames;

use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\entity\Effect;
use pocketmine\event\entity\EntityDamageByEntityEvent;
use pocketmine\event\Listener;
use pocketmine\event\player\PlayerDeathEvent;
use pocketmine\event\player\PlayerQuitEvent;
use pocketmine\event\player\PlayerRespawnEvent;
use pocketmine\item\Item;
use pocketmine\level\Position;
use pocketmine\plugin\PluginBase;
use pocketmine\Player;
use pocketmine\utils\TextFormat;

class Main extends PluginBase implements Listener{

    const STATE_WAITING = 0;
    const STATE_COUNTDOWN = 1;
    const STATE_RUNNING = 2;

    /** @var int */
    private $state = self::STATE_WAITING;

    /** @var int */
    private $countdownLeft = 0;

    /** @var Player[] */
    private $players = [];

    /** @var Player[] */
    private $alivePlayers = [];

    /** @var Position[] */
    private $returnPositions = [];

    /** @var Player[] */
    private $pendingRespawn = [];

    public function onEnable(){
        @mkdir($this->getDataFolder());
        $this->saveDefaultConfig();

        $this->getServer()->getPluginManager()->registerEvents($this, $this);
        $this->getServer()->getScheduler()->scheduleRepeatingTask(new GameTask($this), 20);

        $this->getLogger()->info("MiniGames enabled.");
    }

    public function onDisable(){
        foreach($this->players as $name => $player){
            $this->returnPlayer($player);
            unset($this->players[$name]);
        }
        $this->alivePlayers = [];
        $this->pendingRespawn = [];
        $this->state = self::STATE_WAITING;
    }

    public function onCommand(CommandSender $sender, Command $command, $label, array $args){
        if(strtolower($command->getName()) !== "minigame"){
            return false;
        }

        if(count($args) < 1){
            $sender->sendMessage(TextFormat::YELLOW . "Usage: /minigame <join|leave|start|status>");
            return true;
        }

        $sub = strtolower($args[0]);
        switch($sub){
            case "join":
                if(!($sender instanceof Player)){
                    $sender->sendMessage(TextFormat::RED . "This command can only be used in-game.");
                    return true;
                }
                $this->joinArena($sender);
                return true;

            case "leave":
                if(!($sender instanceof Player)){
                    $sender->sendMessage(TextFormat::RED . "This command can only be used in-game.");
                    return true;
                }
                $this->leaveArena($sender, true);
                return true;

            case "start":
                if(!$sender->hasPermission("minigame.command.start")){
                    $sender->sendMessage(TextFormat::RED . "You don't have permission.");
                    return true;
                }
                if($this->state === self::STATE_RUNNING){
                    $sender->sendMessage(TextFormat::RED . "Game is already running.");
                    return true;
                }
                if(count($this->players) < $this->getMinPlayers()){
                    $sender->sendMessage(TextFormat::RED . "Not enough players to start.");
                    return true;
                }
                $this->startCountdown(5);
                $sender->sendMessage(TextFormat::GREEN . "Forced start initiated.");
                return true;

            case "status":
                $sender->sendMessage(TextFormat::AQUA . "State: " . $this->getStateName());
                $sender->sendMessage(TextFormat::AQUA . "Players: " . count($this->players) . "/" . $this->getMaxPlayers());
                return true;
        }

        $sender->sendMessage(TextFormat::YELLOW . "Usage: /minigame <join|leave|start|status>");
        return true;
    }

    public function tickGame(){
        if($this->state === self::STATE_WAITING){
            if(count($this->players) >= $this->getMinPlayers()){
                $this->startCountdown($this->getCountdown());
            }
            return;
        }

        if($this->state === self::STATE_COUNTDOWN){
            if(count($this->players) < $this->getMinPlayers()){
                $this->broadcastToGame(TextFormat::RED . "Countdown cancelled: not enough players.");
                $this->state = self::STATE_WAITING;
                return;
            }

            if($this->countdownLeft <= 0){
                $this->startGame();
                return;
            }

            if($this->countdownLeft <= 5 || $this->countdownLeft % 5 === 0){
                $this->broadcastToGame(TextFormat::GOLD . "Game starts in " . TextFormat::YELLOW . $this->countdownLeft . TextFormat::GOLD . " seconds...");
            }
            $this->countdownLeft--;
            return;
        }

        if($this->state === self::STATE_RUNNING){
            $this->checkWinCondition();
        }
    }

    public function onQuit(PlayerQuitEvent $event){
        $this->leaveArena($event->getPlayer(), false);
    }

    public function onDamage(EntityDamageByEntityEvent $event){
        $entity = $event->getEntity();
        $damager = $event->getDamager();

        if(!($entity instanceof Player) || !($damager instanceof Player)){
            return;
        }

        $inGameVictim = isset($this->players[strtolower($entity->getName())]);
        $inGameAttacker = isset($this->players[strtolower($damager->getName())]);

        if($inGameVictim xor $inGameAttacker){
            $event->setCancelled(true);
            return;
        }

        if($inGameVictim && $this->state !== self::STATE_RUNNING){
            $event->setCancelled(true);
        }
    }

    public function onPlayerDeath(PlayerDeathEvent $event){
        $player = $event->getEntity();
        $name = strtolower($player->getName());

        if(!isset($this->players[$name])){
            return;
        }

        $event->setKeepInventory(true);
        $event->setKeepExperience(true);
        $event->setDrops([]);

        unset($this->alivePlayers[$name]);
        $this->pendingRespawn[$name] = $player;

        $this->broadcastToGame(TextFormat::RED . $player->getName() . " has been eliminated!");
        $this->checkWinCondition();
    }

    public function onRespawn(PlayerRespawnEvent $event){
        $player = $event->getPlayer();
        $name = strtolower($player->getName());

        if(!isset($this->pendingRespawn[$name])){
            return;
        }

        unset($this->pendingRespawn[$name]);

        $event->setRespawnPosition($this->getLobbyPosition());

        if(isset($this->players[$name])){
            unset($this->players[$name]);
        }

        if(isset($this->returnPositions[$name])){
            unset($this->returnPositions[$name]);
        }

        $player->getInventory()->clearAll();
        $player->removeAllEffects();
        $player->sendMessage(TextFormat::YELLOW . "You were eliminated and returned to lobby.");

        if(count($this->players) < $this->getMinPlayers() && $this->state !== self::STATE_WAITING){
            $this->endGame(null, TextFormat::RED . "Game ended: not enough players.");
        }
    }

    private function joinArena(Player $player){
        $name = strtolower($player->getName());

        if(isset($this->players[$name])){
            $player->sendMessage(TextFormat::RED . "You are already in the mini game.");
            return;
        }

        if(count($this->players) >= $this->getMaxPlayers()){
            $player->sendMessage(TextFormat::RED . "Arena is full.");
            return;
        }

        $this->returnPositions[$name] = Position::fromObject($player, $player->getLevel());
        $this->players[$name] = $player;

        $player->teleport($this->getLobbyPosition());
        $player->getInventory()->clearAll();
        $player->removeAllEffects();
        $player->sendMessage(TextFormat::GREEN . "You joined MiniGames queue.");

        $this->broadcastToGame(TextFormat::AQUA . $player->getName() . " joined the queue (" . count($this->players) . "/" . $this->getMaxPlayers() . ")");
    }

    private function leaveArena(Player $player, $notify){
        $name = strtolower($player->getName());

        $wasInGame = isset($this->players[$name]) || isset($this->pendingRespawn[$name]);
        if(!$wasInGame){
            if($notify){
                $player->sendMessage(TextFormat::RED . "You are not in the mini game.");
            }
            return;
        }

        unset($this->players[$name], $this->alivePlayers[$name], $this->pendingRespawn[$name]);
        $this->returnPlayer($player);

        if($notify){
            $player->sendMessage(TextFormat::YELLOW . "You left the mini game.");
        }

        if($this->state === self::STATE_RUNNING){
            $this->broadcastToGame(TextFormat::YELLOW . $player->getName() . " left the game.");
            $this->checkWinCondition();
        }

        if(count($this->players) < $this->getMinPlayers() && $this->state !== self::STATE_WAITING){
            $this->state = self::STATE_WAITING;
            $this->broadcastToGame(TextFormat::RED . "Back to waiting mode: not enough players.");
        }
    }

    private function startCountdown($seconds){
        $this->state = self::STATE_COUNTDOWN;
        $this->countdownLeft = (int) $seconds;
        $this->broadcastToGame(TextFormat::GOLD . "Countdown started!");
    }

    private function startGame(){
        if(count($this->players) < $this->getMinPlayers()){
            $this->state = self::STATE_WAITING;
            return;
        }

        $this->state = self::STATE_RUNNING;
        $this->alivePlayers = [];

        $spawns = $this->getArenaSpawns();
        shuffle($spawns);
        $index = 0;

        foreach($this->players as $name => $player){
            if(!$player->isOnline()){
                unset($this->players[$name]);
                continue;
            }

            $spawn = $spawns[$index % count($spawns)];
            $index++;

            $player->teleport($spawn);
            $player->setHealth($player->getMaxHealth());
            $player->getInventory()->clearAll();
            $player->getInventory()->addItem(Item::get(Item::WOODEN_SWORD, 0, 1));
            $player->getInventory()->addItem(Item::get(Item::MUSHROOM_STEW, 0, 3));
            $player->addEffect(Effect::getEffect(Effect::SATURATION)->setDuration(20 * 3600)->setAmplifier(0));

            $this->alivePlayers[$name] = $player;
        }

        $this->broadcastToGame(TextFormat::GREEN . "Game started! Last alive player wins.");
    }

    private function checkWinCondition(){
        if($this->state !== self::STATE_RUNNING){
            return;
        }

        foreach($this->alivePlayers as $name => $player){
            if(!$player->isOnline() || !isset($this->players[$name])){
                unset($this->alivePlayers[$name]);
            }
        }

        if(count($this->alivePlayers) > 1){
            return;
        }

        $winner = null;
        if(count($this->alivePlayers) === 1){
            $winner = array_values($this->alivePlayers)[0];
        }

        $message = $winner instanceof Player
            ? TextFormat::GREEN . "Winner: " . TextFormat::YELLOW . $winner->getName()
            : TextFormat::RED . "No winner this round.";

        $this->endGame($winner, $message);
    }

    private function endGame($winner = null, $message = ""){
        if($message !== ""){
            $this->getServer()->broadcastMessage(TextFormat::LIGHT_PURPLE . "[MiniGames] " . $message);
        }

        foreach($this->players as $name => $player){
            if($player instanceof Player && $player->isOnline()){
                $this->returnPlayer($player);
                $player->sendMessage(TextFormat::AQUA . "Round ended. Thanks for playing!");
            }
        }

        $this->players = [];
        $this->alivePlayers = [];
        $this->pendingRespawn = [];
        $this->returnPositions = [];
        $this->state = self::STATE_WAITING;
        $this->countdownLeft = 0;
    }

    private function returnPlayer(Player $player){
        $name = strtolower($player->getName());

        $player->getInventory()->clearAll();
        $player->removeAllEffects();

        if(isset($this->returnPositions[$name])){
            $player->teleport($this->returnPositions[$name]);
            unset($this->returnPositions[$name]);
        }
    }

    private function getLobbyPosition(){
        $arena = $this->getConfig()->get("arena");
        $levelName = isset($arena["level"]) ? $arena["level"] : "world";

        if($this->getServer()->getLevelByName($levelName) === null){
            $this->getServer()->loadLevel($levelName);
        }

        $level = $this->getServer()->getLevelByName($levelName);
        $lobby = isset($arena["lobby"]) ? $arena["lobby"] : ["x" => 128, "y" => 70, "z" => 128];

        return new Position((float) $lobby["x"], (float) $lobby["y"], (float) $lobby["z"], $level);
    }

    /**
     * @return Position[]
     */
    private function getArenaSpawns(){
        $arena = $this->getConfig()->get("arena");
        $levelName = isset($arena["level"]) ? $arena["level"] : "world";

        if($this->getServer()->getLevelByName($levelName) === null){
            $this->getServer()->loadLevel($levelName);
        }

        $level = $this->getServer()->getLevelByName($levelName);

        $spawns = [];
        if(isset($arena["spawns"]) && is_array($arena["spawns"])){
            foreach($arena["spawns"] as $spawn){
                $spawns[] = new Position((float) $spawn["x"], (float) $spawn["y"], (float) $spawn["z"], $level);
            }
        }

        if(count($spawns) === 0){
            $spawns[] = new Position(140, 70, 140, $level);
            $spawns[] = new Position(116, 70, 116, $level);
        }

        return $spawns;
    }

    private function getMinPlayers(){
        return (int) $this->getConfig()->getNested("settings.minPlayers", 2);
    }

    private function getCountdown(){
        return (int) $this->getConfig()->getNested("settings.countdown", 20);
    }

    private function getMaxPlayers(){
        return (int) $this->getConfig()->getNested("settings.maxPlayers", 12);
    }

    private function getStateName(){
        switch($this->state){
            case self::STATE_COUNTDOWN:
                return "COUNTDOWN";
            case self::STATE_RUNNING:
                return "RUNNING";
            default:
                return "WAITING";
        }
    }

    private function broadcastToGame($message){
        foreach($this->players as $player){
            if($player instanceof Player && $player->isOnline()){
                $player->sendMessage(TextFormat::LIGHT_PURPLE . "[MiniGames] " . $message);
            }
        }
    }
}
