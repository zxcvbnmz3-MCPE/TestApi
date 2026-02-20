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

        $worldName = isset($args[1]) ? $args[1] : 'ctf_world';
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

        $this->buildArena($level);
        $this->updateCtfPluginConfig($worldName);

        $sender->sendMessage('§aCTF world generated: §e' . $worldName);
        $sender->sendMessage('§aMap + spawns + flag points created automatically.');
    }

    private function buildArena($level){
        $centerX = 0;
        $centerZ = 0;
        $groundY = 64;

        // Main platform
        for($x = -80; $x <= 80; $x++){
            for($z = -80; $z <= 80; $z++){
                $level->setBlock(new Vector3($centerX + $x, $groundY, $centerZ + $z), Block::get(Block::GRASS), true, false);
                $level->setBlock(new Vector3($centerX + $x, $groundY - 1, $centerZ + $z), Block::get(Block::DIRT), true, false);
            }
        }

        // Border walls
        for($x = -82; $x <= 82; $x++){
            for($y = $groundY + 1; $y <= $groundY + 4; $y++){
                $level->setBlock(new Vector3($centerX + $x, $y, $centerZ - 82), Block::get(Block::COBBLESTONE), true, false);
                $level->setBlock(new Vector3($centerX + $x, $y, $centerZ + 82), Block::get(Block::COBBLESTONE), true, false);
            }
        }
        for($z = -82; $z <= 82; $z++){
            for($y = $groundY + 1; $y <= $groundY + 4; $y++){
                $level->setBlock(new Vector3($centerX - 82, $y, $centerZ + $z), Block::get(Block::COBBLESTONE), true, false);
                $level->setBlock(new Vector3($centerX + 82, $y, $centerZ + $z), Block::get(Block::COBBLESTONE), true, false);
            }
        }

        // Middle bridge
        for($z = -60; $z <= 60; $z++){
            for($x = -4; $x <= 4; $x++){
                $level->setBlock(new Vector3($centerX + $x, $groundY + 1, $centerZ + $z), Block::get(Block::WOODEN_PLANKS), true, false);
            }
        }

        // Red base
        $this->buildBase($level, -55, $groundY + 1, 0, 14, Block::REDSTONE_BLOCK);

        // Blue base
        $this->buildBase($level, 55, $groundY + 1, 0, 11, Block::LAPIS_BLOCK);

        // Central decorations
        for($x = -2; $x <= 2; $x++){
            for($z = -2; $z <= 2; $z++){
                $level->setBlock(new Vector3($x, $groundY + 2, $z), Block::get(Block::GLOWSTONE), true, false);
            }
        }

        // Spawn point
        $level->setSpawnLocation(new Vector3(0, $groundY + 2, 0));
    }

    private function buildBase($level, $x, $y, $z, $woolMeta, $flagBlockId){
        // Circular-ish platform
        for($ix = -10; $ix <= 10; $ix++){
            for($iz = -10; $iz <= 10; $iz++){
                if(($ix * $ix + $iz * $iz) <= 100){
                    $level->setBlock(new Vector3($x + $ix, $y, $z + $iz), Block::get(Block::WOOL, $woolMeta), true, false);
                }
            }
        }

        // Small tower / flag stand
        for($iy = 1; $iy <= 5; $iy++){
            $level->setBlock(new Vector3($x, $y + $iy, $z), Block::get(Block::QUARTZ_BLOCK), true, false);
        }

        // Flag marker block on top
        $level->setBlock(new Vector3($x, $y + 6, $z), Block::get($flagBlockId), true, false);

        // Team spawn pad
        for($ix = -2; $ix <= 2; $ix++){
            for($iz = -2; $iz <= 2; $iz++){
                $level->setBlock(new Vector3($x + $ix, $y + 1, $z + $iz), Block::get(Block::COBBLESTONE), true, false);
            }
        }
    }

    private function updateCtfPluginConfig($worldName){
        $ctf = $this->getServer()->getPluginManager()->getPlugin('CTF');
        if($ctf === null || !$ctf->isEnabled() || !method_exists($ctf, 'getConfig')){
            return;
        }

        $config = $ctf->getConfig();

        $config->setNested('arena.level', $worldName);
        $config->setNested('arena.lobby', ['x' => 0, 'y' => 66, 'z' => 0]);

        $config->setNested('teams.red.spawn', ['x' => -55, 'y' => 66, 'z' => 0]);
        $config->setNested('teams.red.flag', ['x' => -55, 'y' => 71, 'z' => 0]);

        $config->setNested('teams.blue.spawn', ['x' => 55, 'y' => 66, 'z' => 0]);
        $config->setNested('teams.blue.flag', ['x' => 55, 'y' => 71, 'z' => 0]);

        $config->save();
    }
}
