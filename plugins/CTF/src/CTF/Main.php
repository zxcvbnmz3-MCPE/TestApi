<?php
namespace CTF;

use CTF\game\GameManager;
use CTF\game\InventoryManager;
use CTF\npc\NpcManager;
use CTF\task\GameTickTask;
use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\event\entity\EntityDamageByEntityEvent;
use pocketmine\event\entity\EntityDamageEvent;
use pocketmine\event\player\PlayerDeathEvent;
use pocketmine\event\player\PlayerQuitEvent;
use pocketmine\event\Listener;
use pocketmine\level\Position;
use pocketmine\Player;
use pocketmine\plugin\PluginBase;
use pocketmine\utils\Config;

class Main extends PluginBase implements Listener{

    /** @var InventoryManager */
    private $inventoryManager;

    /** @var GameManager */
    private $gameManager;

    /** @var NpcManager */
    private $npcManager;

    /** @var Config */
    private $stats;

    public function onEnable(){
        @mkdir($this->getDataFolder());
        $this->saveDefaultConfig();

        $this->stats = new Config($this->getDataFolder() . 'stats.yml', Config::YAML, []);
        $this->inventoryManager = new InventoryManager();
        $this->gameManager = new GameManager($this);
        $this->npcManager = new NpcManager($this);

        $this->getServer()->getPluginManager()->registerEvents($this, $this);
        $this->getServer()->getScheduler()->scheduleRepeatingTask(new GameTickTask($this), 20);

        $this->npcManager->spawnConfiguredNpcs();
        $this->getLogger()->info('CTF plugin enabled.');
    }

    public function onCommand(CommandSender $sender, Command $command, $label, array $args){
        if(strtolower($command->getName()) !== 'ctf'){
            return false;
        }

        if(!isset($args[0])){
            $sender->sendMessage('§e/ctf <join [bot]|leave|start|stop|status|top|setnpc>');
            return true;
        }

        switch(strtolower($args[0])){
            case 'join':
                if($sender instanceof Player){
                    if(isset($args[1]) && strtolower($args[1]) === 'bot'){
                        $this->gameManager->joinWithBot($sender);
                    }else{
                        $this->gameManager->join($sender);
                    }
                }
                return true;

            case 'leave':
                if($sender instanceof Player){
                    $this->gameManager->leave($sender, true);
                }
                return true;

            case 'start':
                if(!$sender->hasPermission('ctf.admin')){
                    $sender->sendMessage('§cNo permission.');
                    return true;
                }
                $this->gameManager->forceStart();
                return true;

            case 'stop':
                if(!$sender->hasPermission('ctf.admin')){
                    $sender->sendMessage('§cNo permission.');
                    return true;
                }
                $this->gameManager->stop();
                return true;

            case 'status':
                foreach($this->gameManager->getStatusLines() as $line){
                    $sender->sendMessage('§b' . $line);
                }
                return true;

            case 'top':
                $this->sendTop($sender);
                return true;

            case 'setnpc':
                if(!$sender->hasPermission('ctf.admin') || !($sender instanceof Player)){
                    $sender->sendMessage('§cNo permission.');
                    return true;
                }
                $this->npcManager->addGuideNpc(Position::fromObject($sender, $sender->getLevel()));
                $sender->sendMessage('§aCTF guide NPC created and saved.');
                return true;
        }

        $sender->sendMessage('§e/ctf <join [bot]|leave|start|stop|status|top|setnpc>');
        return true;
    }

    public function onQuit(PlayerQuitEvent $event){
        $this->gameManager->leave($event->getPlayer(), false);
    }

    public function onDeath(PlayerDeathEvent $event){
        $player = $event->getEntity();

        if(!$this->gameManager->isParticipant($player)){
            return;
        }

        $event->setKeepInventory(true);
        $event->setKeepExperience(true);
        $event->setDrops([]);

        $killer = null;
        $cause = $player->getLastDamageCause();
        if($cause instanceof EntityDamageByEntityEvent && $cause->getDamager() instanceof Player){
            $killer = $cause->getDamager();
        }

        $this->gameManager->onPlayerKilled($player, $killer);
    }

    public function onDamage(EntityDamageEvent $event){
        if(!$event instanceof EntityDamageByEntityEvent){
            return;
        }

        $damager = $event->getDamager();
        if($damager instanceof Player){
            $this->gameManager->onHitNpcGuide($damager, $event);
        }
    }

    public function getInventoryManager(){ return $this->inventoryManager; }
    public function getGameManager(){ return $this->gameManager; }
    public function getNpcManager(){ return $this->npcManager; }

    public function getMinPlayers(){ return (int) $this->getConfig()->getNested('settings.minPlayers', 2); }
    public function getCountdown(){ return (int) $this->getConfig()->getNested('settings.countdown', 20); }
    public function getRespawnDelay(){ return (int) $this->getConfig()->getNested('settings.respawnDelay', 3); }
    public function getMaxScore(){ return (int) $this->getConfig()->getNested('settings.maxScore', 3); }
    public function isPopupEnabled(){ return (bool) $this->getConfig()->getNested('settings.popupEnabled', true); }
    public function isParticlesEnabled(){ return (bool) $this->getConfig()->getNested('settings.particlesEnabled', true); }

    public function addStat($name, $key, $value){
        $id = strtolower($name);
        $row = (array) $this->stats->get($id, ['wins' => 0, 'kills' => 0, 'captures' => 0]);
        $row[$key] = ((int) ($row[$key] ?? 0)) + (int) $value;
        $this->stats->set($id, $row);
        $this->stats->save();
    }

    private function sendTop(CommandSender $sender){
        $data = $this->stats->getAll();
        uasort($data, function($a, $b){
            return (($b['wins'] ?? 0) <=> ($a['wins'] ?? 0));
        });

        $sender->sendMessage('§6=== CTF Top Wins ===');
        $i = 1;
        foreach($data as $name => $row){
            $sender->sendMessage('§e#' . $i . ' §f' . $name . ' §7- §a' . (int) ($row['wins'] ?? 0) . ' wins §7| §b' . (int) ($row['captures'] ?? 0) . ' caps');
            if(++$i > 10){
                break;
            }
        }
    }
}
