<?php
namespace MiniGames;

use pocketmine\scheduler\PluginTask;

class GameTask extends PluginTask{

    public function onRun($currentTick){
        /** @var Main $owner */
        $owner = $this->getOwner();
        $owner->tickGame();
    }
}
