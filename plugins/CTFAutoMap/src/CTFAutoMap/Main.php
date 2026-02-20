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

        $worldName = isset($args[1]) ? (string) $args[1] : 'ctf_void';
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

        $this->prepareChunks($level, -9, 9, -9, 9);
        $this->buildVoidArena($level);
        $level->save(true);

        $this->updateCtfPluginConfig($worldName);

        $sender->sendMessage('§aVoid CTF world generated: §e' . $worldName);
        $sender->sendMessage('§aCustom islands + bridges + spawns + flags were built.');
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

    private function buildVoidArena($level){
        $baseY = 70;

        // Clear to void (keep only y0 bedrock layers untouched)
        for($x = -150; $x <= 150; $x++){
            for($z = -150; $z <= 150; $z++){
                for($y = 1; $y <= 120; $y++){
                    $level->setBlock(new Vector3($x, $y, $z), Block::get(Block::AIR), true, false);
                }
            }
        }

        // Center island
        $this->buildIsland($level, 0, $baseY, 0, 18, Block::STONE_BRICKS);

        // Team islands
        $this->buildIsland($level, -70, $baseY, 0, 16, Block::WOOL, 14);
        $this->buildIsland($level, 70, $baseY, 0, 16, Block::WOOL, 11);

        // Bridges
        $this->buildBridge($level, -52, $baseY + 1, 0, -18, $baseY + 1, 0, Block::WOODEN_PLANKS);
        $this->buildBridge($level, 18, $baseY + 1, 0, 52, $baseY + 1, 0, Block::WOODEN_PLANKS);

        // Side mini islands
        $this->buildIsland($level, 0, $baseY - 1, -45, 8, Block::COBBLESTONE);
        $this->buildIsland($level, 0, $baseY - 1, 45, 8, Block::COBBLESTONE);

        // Flag towers
        $this->buildFlagTower($level, -70, $baseY + 1, 0, Block::REDSTONE_BLOCK);
        $this->buildFlagTower($level, 70, $baseY + 1, 0, Block::LAPIS_BLOCK);

        // Spawn pads
        $this->buildSpawnPad($level, -70, $baseY + 1, 0);
        $this->buildSpawnPad($level, 70, $baseY + 1, 0);

        // Lobby spawn
        $this->buildSpawnPad($level, 0, $baseY + 2, 0);
        $level->setSpawnLocation(new Vector3(0, $baseY + 3, 0));
    }

    private function buildIsland($level, $cx, $cy, $cz, $radius, $blockId, $meta = 0){
        for($x = -$radius; $x <= $radius; $x++){
            for($z = -$radius; $z <= $radius; $z++){
                $dist = sqrt($x * $x + $z * $z);
                if($dist <= $radius){
                    $height = (int) max(1, 3 - floor($dist / max(1, $radius / 4)));
                    for($h = 0; $h < $height; $h++){
                        $level->setBlock(new Vector3($cx + $x, $cy - $h, $cz + $z), Block::get($blockId, $meta), true, false);
                    }
                }
            }
        }
    }

    private function buildBridge($level, $x1, $y1, $z1, $x2, $y2, $z2, $blockId){
        $minX = min($x1, $x2);
        $maxX = max($x1, $x2);
        $minZ = min($z1, $z2);
        $maxZ = max($z1, $z2);

        for($x = $minX; $x <= $maxX; $x++){
            for($z = $minZ - 2; $z <= $maxZ + 2; $z++){
                $level->setBlock(new Vector3($x, $y1, $z), Block::get($blockId), true, false);
            }
        }
    }

    private function buildFlagTower($level, $x, $y, $z, $flagBlock){
        for($i = 0; $i <= 6; $i++){
            $level->setBlock(new Vector3($x, $y + $i, $z), Block::get(Block::QUARTZ_BLOCK), true, false);
        }
        $level->setBlock(new Vector3($x, $y + 7, $z), Block::get($flagBlock), true, false);
    }

    private function buildSpawnPad($level, $x, $y, $z){
        for($ix = -2; $ix <= 2; $ix++){
            for($iz = -2; $iz <= 2; $iz++){
                $level->setBlock(new Vector3($x + $ix, $y, $z + $iz), Block::get(Block::GLOWSTONE), true, false);
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
        $config->setNested('arena.lobby', ['x' => 0, 'y' => 73, 'z' => 0]);

        $config->setNested('teams.red.spawn', ['x' => -70, 'y' => 72, 'z' => 0]);
        $config->setNested('teams.red.flag', ['x' => -70, 'y' => 78, 'z' => 0]);

        $config->setNested('teams.blue.spawn', ['x' => 70, 'y' => 72, 'z' => 0]);
        $config->setNested('teams.blue.flag', ['x' => 70, 'y' => 78, 'z' => 0]);

        $config->save();
    }
}
