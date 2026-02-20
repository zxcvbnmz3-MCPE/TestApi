<?php
namespace CTF\npc;

use CTF\Main;
use pocketmine\level\Position;
use pocketmine\level\particle\FlameParticle;
use pocketmine\level\particle\FloatingTextParticle;
use pocketmine\Player;

class NpcManager{

    /** @var Main */
    private $plugin;

    /** @var int[] */
    private $lastHint = [];

    public function __construct(Main $plugin){
        $this->plugin = $plugin;
    }

    public function spawnConfiguredNpcs(){
        // Compatibility mode: no runtime entity spawn required.
        // We only persist and render lightweight marker particles/text.
        $guides = (array) $this->plugin->getConfig()->getNested('npc.guides', []);
        $this->plugin->getConfig()->setNested('npc.guides', $guides);
    }

    public function addGuideNpc(Position $position, $name = 'CTF Guide'){
        $guides = (array) $this->plugin->getConfig()->getNested('npc.guides', []);

        $guides[] = [
            'level' => $position->getLevel()->getName(),
            'x' => round($position->x, 2),
            'y' => round($position->y, 2),
            'z' => round($position->z, 2),
            'name' => $name
        ];

        $this->plugin->getConfig()->setNested('npc.guides', $guides);
        $this->plugin->getConfig()->save();
    }

    /**
     * @param Player[] $players
     */
    public function render(array $players){
        $guides = (array) $this->plugin->getConfig()->getNested('npc.guides', []);

        foreach($guides as $guide){
            if(!isset($guide['level'], $guide['x'], $guide['y'], $guide['z'])){
                continue;
            }

            $level = $this->plugin->getServer()->getLevelByName($guide['level']);
            if($level === null){
                continue;
            }

            $pos = new Position((float) $guide['x'], (float) $guide['y'], (float) $guide['z'], $level);
            $level->addParticle(new FlameParticle($pos->add(0, 1.2, 0)));
            $level->addParticle(new FloatingTextParticle($pos->add(0, 2.2, 0), 'Hit any entity near this point', '§e' . (isset($guide['name']) ? $guide['name'] : 'CTF Guide')));

            foreach($players as $player){
                if(!$player instanceof Player || !$player->isOnline()) continue;
                if($player->getLevel()->getName() !== $guide['level']) continue;

                if($player->distance($pos) <= 4.0){
                    $pn = strtolower($player->getName());
                    $now = time();
                    if(!isset($this->lastHint[$pn]) || ($now - $this->lastHint[$pn]) >= 5){
                        $player->sendPopup('§6CTF Guide: §e/ctf join §7| §e/ctf join bot §7| §e/ctf leave');
                        $this->lastHint[$pn] = $now;
                    }
                }
            }
        }
    }
}
