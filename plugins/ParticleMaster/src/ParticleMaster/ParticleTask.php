<?php
namespace ParticleMaster;

use pocketmine\scheduler\PluginTask;

class ParticleTask extends PluginTask{

    public function onRun($currentTick){
        /** @var Main $owner */
        $owner = $this->getOwner();
        $owner->tickParticles();
    }
}
