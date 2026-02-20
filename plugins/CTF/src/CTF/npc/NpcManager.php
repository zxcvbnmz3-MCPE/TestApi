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
        foreach((array) $this->plugin->getConfig()->getNested('npc.guides', []) as $npc){
            if(isset($npc['level'], $npc['x'], $npc['y'], $npc['z'])){
                $this->spawnNpc($npc['level'], $npc['x'], $npc['y'], $npc['z'], isset($npc['name']) ? $npc['name'] : 'CTF Guide');
            }
        }
    }

    public function addGuideNpc(Position $position, $name = 'CTF Guide'){
        $guides = (array) $this->plugin->getConfig()->getNested('npc.guides', []);

        $entry = [
            'level' => $position->getLevel()->getName(),
            'x' => round($position->x, 2),
            'y' => round($position->y, 2),
            'z' => round($position->z, 2),
            'name' => $name
        ];

        $guides[] = $entry;
        $this->plugin->getConfig()->setNested('npc.guides', $guides);
        $this->plugin->getConfig()->save();

        $this->spawnNpc($entry['level'], $entry['x'], $entry['y'], $entry['z'], $entry['name']);
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

        $chunkX = ((int) $x) >> 4;
        $chunkZ = ((int) $z) >> 4;
        if(!$level->isChunkGenerated($chunkX, $chunkZ)){
            $level->generateChunk($chunkX, $chunkZ, true);
        }
        $level->populateChunk($chunkX, $chunkZ, true);

        $nbt = new Compound('', [
            'Pos' => new ListTag('Pos', [new DoubleTag('', (float) $x), new DoubleTag('', (float) $y), new DoubleTag('', (float) $z)]),
            'Motion' => new ListTag('Motion', [new DoubleTag('', 0.0), new DoubleTag('', 0.0), new DoubleTag('', 0.0)]),
            'Rotation' => new ListTag('Rotation', [new FloatTag('', 0.0), new FloatTag('', 0.0)])
        ]);

        $villager = Entity::createEntity('Villager', $level->getChunk($chunkX, $chunkZ), $nbt);
        if($villager instanceof Entity){
            $villager->setNameTagAlwaysVisible(true);
            $villager->setNameTag('§e' . $name . "\n§7Hit me for CTF help");
            $villager->spawnToAll();
        }
    }
}
