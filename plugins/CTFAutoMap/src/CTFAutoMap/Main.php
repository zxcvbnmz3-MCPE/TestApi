<?php
namespace CTFAutoMap;

use pocketmine\block\Block;
use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\level\generator\Generator;
use pocketmine\math\Vector3;
use pocketmine\plugin\PluginBase;

class Main extends PluginBase{

    public function onCommand(CommandSender $sender, Command $command, $label, array $args){
        if(strtolower($command->getName()) !== 'ctfmap'){
            return false;
        }

        if(!isset($args[0]) || strtolower($args[0]) !== 'create'){
            $sender->sendMessage('§eUse: /ctfmap create [worldName]');
            return true;
        }

        $worldName = isset($args[1]) ? (string) $args[1] : 'ctf_world';
        $this->createCtfWorld($sender, $worldName);
        return true;
    }

    private function createCtfWorld(CommandSender $sender, $worldName){
        $server = $this->getServer();

        if($server->getLevelByName($worldName) === null){
            $server->generateLevel($worldName, mt_rand(), Generator::getGenerator('flat'));
            $server->loadLevel($worldName);
        }

        $level = $server->getLevelByName($worldName);
        if($level === null){
            $sender->sendMessage('§cFailed to generate/load world.');
            return;
        }

        $this->prepareChunks($level, -8, 8, -8, 8);
        $this->buildArena($level);
        $level->save(true);

        $this->updateCtfPluginConfig($worldName);

        $sender->sendMessage('§aCTF world generated: §e' . $worldName);
        $sender->sendMessage('§aArena built (bases, bridge, walls, flags, spawn).');
    }

    private function prepareChunks($level, $minX, $maxX, $minZ, $maxZ){
        for($cx = $minX; $cx <= $maxX; $cx++){
            for($cz = $minZ; $cz <= $maxZ; $cz++){
                if(!$level->isChunkGenerated($cx, $cz)){
                    $level->generateChunk($cx, $cz, true);
                }
                $level->populateChunk($cx, $cz, true);
            }
        }
    }

    private function buildArena($level){
        $groundY = 64;

        for($x = -90; $x <= 90; $x++){
            for($z = -90; $z <= 90; $z++){
                // clear air space
                for($y = 65; $y <= 85; $y++){
                    $level->setBlock(new Vector3($x, $y, $z), Block::get(Block::AIR), true, false);
                }

                $level->setBlock(new Vector3($x, $groundY - 1, $z), Block::get(Block::DIRT), true, false);
                $level->setBlock(new Vector3($x, $groundY, $z), Block::get(Block::GRASS), true, false);
            }
        }

        // walls
        for($x = -92; $x <= 92; $x++){
            for($y = $groundY + 1; $y <= $groundY + 6; $y++){
                $level->setBlock(new Vector3($x, $y, -92), Block::get(Block::STONE_BRICKS), true, false);
                $level->setBlock(new Vector3($x, $y, 92), Block::get(Block::STONE_BRICKS), true, false);
            }
        }
        for($z = -92; $z <= 92; $z++){
            for($y = $groundY + 1; $y <= $groundY + 6; $y++){
                $level->setBlock(new Vector3(-92, $y, $z), Block::get(Block::STONE_BRICKS), true, false);
                $level->setBlock(new Vector3(92, $y, $z), Block::get(Block::STONE_BRICKS), true, false);
            }
        }

        // center bridge
        for($z = -70; $z <= 70; $z++){
            for($x = -5; $x <= 5; $x++){
                $level->setBlock(new Vector3($x, $groundY + 1, $z), Block::get(Block::WOODEN_PLANKS), true, false);
            }
        }

        // center tower
        for($y = $groundY + 1; $y <= $groundY + 12; $y++){
            $level->setBlock(new Vector3(0, $y, 0), Block::get(Block::STONE_BRICKS), true, false);
        }

        $this->buildBase($level, -60, $groundY + 1, 0, 14, Block::REDSTONE_BLOCK);
        $this->buildBase($level, 60, $groundY + 1, 0, 11, Block::LAPIS_BLOCK);

        $level->setSpawnLocation(new Vector3(0, $groundY + 2, 0));
    }

    private function buildBase($level, $x, $y, $z, $woolMeta, $flagBlockId){
        for($ix = -12; $ix <= 12; $ix++){
            for($iz = -12; $iz <= 12; $iz++){
                if(($ix * $ix + $iz * $iz) <= 144){
                    $level->setBlock(new Vector3($x + $ix, $y, $z + $iz), Block::get(Block::WOOL, $woolMeta), true, false);
                }
            }
        }

        // spawn pad
        for($ix = -2; $ix <= 2; $ix++){
            for($iz = -2; $iz <= 2; $iz++){
                $level->setBlock(new Vector3($x + $ix, $y + 1, $z + $iz), Block::get(Block::QUARTZ_BLOCK), true, false);
            }
        }

        // flag stand
        for($iy = 1; $iy <= 6; $iy++){
            $level->setBlock(new Vector3($x, $y + $iy, $z), Block::get(Block::COBBLESTONE), true, false);
        }
        $level->setBlock(new Vector3($x, $y + 7, $z), Block::get($flagBlockId), true, false);
    }

    private function updateCtfPluginConfig($worldName){
        $ctf = $this->getServer()->getPluginManager()->getPlugin('CTF');
        if($ctf === null || !$ctf->isEnabled() || !method_exists($ctf, 'getConfig')){
            return;
        }

        $config = $ctf->getConfig();
        $config->setNested('arena.level', $worldName);
        $config->setNested('arena.lobby', ['x' => 0, 'y' => 66, 'z' => 0]);

        $config->setNested('teams.red.spawn', ['x' => -60, 'y' => 66, 'z' => 0]);
        $config->setNested('teams.red.flag', ['x' => -60, 'y' => 72, 'z' => 0]);

        $config->setNested('teams.blue.spawn', ['x' => 60, 'y' => 66, 'z' => 0]);
        $config->setNested('teams.blue.flag', ['x' => 60, 'y' => 72, 'z' => 0]);

        $config->save();
    }
}
