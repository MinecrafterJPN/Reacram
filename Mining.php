<?php

namespace Reacram\Core;

use pocketmine\math\Vector3;
use pocketmine\block\Block;
use pocketmine\item\Item;
use pocketmine\Server;

class Mining extends Reactension {

    public function init() {
        Server::getInstance()->broadcastMessage("i am created");
    }

    public function run() {
        /* @var $block Block */
        $block = $this->level->getBlock(new Vector3($this->x, $this->y - 1, $this->z));
        if ($block->getID() !== Block::BEDROCK) {
            /* @var $item Item */
            $item = Item::fromString($block->getName()); // block to item

            $this->sendToChest($item);
            $this->move($this->x, $this->y - 1, $this->z);
        } else {
            Server::getInstance()->broadcastMessage("I am stopped");
            $this->manager->stop();
        }
    }

}
