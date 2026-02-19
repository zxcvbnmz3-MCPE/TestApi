<?php
namespace CTF\task;

use CTF\Main;
use pocketmine\scheduler\PluginTask;

class GameTickTask extends PluginTask{

    public function onRun($currentTick){
        /** @var Main $plugin */
        $plugin = $this->getOwner();
        $plugin->getGameManager()->tick();
    }
}
