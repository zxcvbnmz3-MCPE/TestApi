<?php
namespace CTF\npc;

use CTF\Main;
use pocketmine\entity\Entity;
use pocketmine\level\Position;
use pocketmine\nbt\tag\Compound;
use pocketmine\nbt\tag\DoubleTag;
use pocketmine\nbt\tag\FloatTag;
use pocketmine\nbt\tag\ListTag;

class NpcManager{

    /** @var Main */
    private $plugin;

    public function __construct(Main $plugin){
        $this->plugin = $plugin;
    }

    public function spawnConfiguredNpcs(){
        foreach($this->plugin->getConfig()->getNested('npc.guides', []) as $npc){
            $this->spawnNpc($npc['level'], $npc['x'], $npc['y'], $npc['z'], $npc['name']);
        }
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

        $this->spawnNpc($position->getLevel()->getName(), $position->x, $position->y, $position->z, $name);
    }

    private function spawnNpc($levelName, $x, $y, $z, $name){
        $server = $this->plugin->getServer();
        $level = $server->getLevelByName($levelName);
        if($level === null){
            $server->loadLevel($levelName);
            $level = $server->getLevelByName($levelName);
        }
        if($level === null){
            return;
        }

        $nbt = new Compound('', [
            'Pos' => new ListTag('Pos', [new DoubleTag('', (float) $x), new DoubleTag('', (float) $y), new DoubleTag('', (float) $z)]),
            'Motion' => new ListTag('Motion', [new DoubleTag('', 0), new DoubleTag('', 0), new DoubleTag('', 0)]),
            'Rotation' => new ListTag('Rotation', [new FloatTag('', 0), new FloatTag('', 0)])
        ]);

        $villager = Entity::createEntity('Villager', $level->getChunk((int) $x >> 4, (int) $z >> 4), $nbt);
        if($villager !== null){
            $villager->setNameTagAlwaysVisible(true);
            $villager->setNameTag('§e' . $name . "\n§7Hit me for CTF help");
            $villager->spawnToAll();
        }
    }
}
