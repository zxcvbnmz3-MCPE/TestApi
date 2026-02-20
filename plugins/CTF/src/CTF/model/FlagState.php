<?php
namespace CTF\model;

use pocketmine\Player;
use pocketmine\level\Position;

class FlagState{

    /** @var Team */
    private $team;
    /** @var Position */
    private $base;
    /** @var Position */
    private $position;
    /** @var Player|null */
    private $carrier = null;
    /** @var bool */
    private $atBase = true;

    public function __construct(Team $team, Position $base){
        $this->team = $team;
        $this->base = $base;
        $this->position = $base;
    }

    public function getTeam(){ return $this->team; }
    public function getBase(){ return $this->base; }
    public function getPosition(){ return $this->position; }
    public function setPosition(Position $position){ $this->position = $position; }

    public function isAtBase(){ return $this->atBase; }
    public function setAtBase($value){ $this->atBase = (bool) $value; }

    public function getCarrier(){ return $this->carrier; }
    public function setCarrier(Player $player = null){ $this->carrier = $player; }

    public function reset(){
        $this->carrier = null;
        $this->atBase = true;
        $this->position = $this->base;
    }
}
