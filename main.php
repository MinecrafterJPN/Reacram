<?php

namespace Reacram\Core;

use pocketmine\utils\TextFormat as Color;
use pocketmine\command\CommandSender;
use pocketmine\command\CommandExecutor;
use pocketmine\event\player\PlayerInteractEvent;
use pocketmine\event\block\BlockBreakEvent;
use pocketmine\tile\Chest as TileChest;
use pocketmine\block\Chest as ChestBlock;
use pocketmine\block\Block as Block;
use pocketmine\level\Level as Level;
use pocketmine\item\Item as Item;
use pocketmine\scheduler\PluginTask;
use pocketmine\plugin\PluginBase;
use pocketmine\math\Vector3;
use pocketmine\command\Command;
use pocketmine\event\Listener;
use pocketmine\utils\Config;
use pocketmine\Server;

class Main extends PluginBase implements Listener, CommandExecutor {

    const REACTENSION_PATH = "reactension/";

    public $config,
            $nameTask = array(),
            $linkTask = array(),
            $executedReacrams = array(),
            $reactensionManagers = array();

    public function onEnable() {

        $this->getServer()->getPluginManager()->registerEvents($this, $this);
        $this->getServer()->getScheduler()->scheduleRepeatingTask(new Tasker($this), 20);
        $this->saveDefaultConfig();
        $this->saveResource("reacrams.yml", false);
        $this->config = new Config($this->getDataFolder() . "reacrams.yml");
        foreach ($level = $this->getServer()->getLevels() as $lvl) {
            $this->getLogger()->info($lvl->getName() . "|" . $lvl->getID());
        }
        foreach ($this->config->getAll() as $name => $info) {
            $level = $this->getServer()->getLevelByName($info['levelname']);

            $chest = false;
            if ($info['islinked']) {
                $chest = $level->getBlock(new Vector3($info['chestx'], $info['chesty'], $info['chestz']));
            }
            $this->reactensionManagers[$name] = new ReactensionManager($info['x'], $info['y'], $info['z'], $level, $info['filepath'], $chest);
        }
    }

    public function onLoad() {
        parent::onLoad();
    }

    public function onCommand(CommandSender $sender, Command $command, $label, array $args) {
        $output = "";
        $issuer = $sender->getName();
        $this->getLogger()->info(Color::DARK_GREEN . $command->getName() . " " . implode("|", $args));
        $subCmd = strtolower(array_shift($args));
        switch ($subCmd) {
            case "":
                $output .= "[Reacram] /reacram <help | list | name | load | link | run | stop>\n";
                break;
            case "help":
                $output .= "[Reacram] /reacram help : Show help\n";
                $output .= "[Reacram] /reacram list : Show list of reactensions\n";
                $output .= "[Reacram] /reacram name <name> : Name reactor <name>\n";
                $output .= "[Reacram] /reacram load <name> <reactension>: Load <reactension> into <name>\n";
                $output .= "[Reacram] /reacram link <name> : Link chest to <name>\n";
                $output .= "[Reacram] /reacram run <name> : Run the program loaded into <name>\n";
                $output .= "[Reacram] /reacram stop <name> : Stop the program loaded into <name>\n";
                break;
            case "list":
                $output .= "[Reacram] List of reactension\n";
                foreach (scandir($this->getDataFolder() . self::REACTENSION_PATH) as $filename) {
                    if (substr($filename, -4) === ".php") {
                        $output .= "[Reacram] " . basename($filename, ".php") . "\n";
                    }
                }
                foreach ($this->reactensionManagers as $name => $reactensionManager) {
                    $output .= "\n" . $name . "\n" .
                            ($reactensionManager->isLinked() ? "Linked" : "Not linked") . "\n" .
                            ($reactensionManager->isLoaded() ? "Loaded" : "Not loaded") . "\n";
                    if ($reactensionManager->isLoaded())
                        $output.= ($reactensionManager->filepath);
                }
                break;
            case "load":
                $name = strtolower(array_shift($args));
                if (!isset($this->reactensionManagers[$name])) {
                    $output .= "[Reacram][Error] \"$name\" is not found\n";
                    break;
                }
                $filename = array_shift($args);
                $filenameWithExt = (substr($filename, -4) === ".php") ? $filename : $filename . ".php";
                $filepath = $this->getDataFolder() . self::REACTENSION_PATH . $filenameWithExt;
                if (!file_exists($filepath)) {
                    $output .= "[Reacram][Error] \"$filename\" is not found\n";
                    break;
                }
                if ($this->reactensionManagers[$name]->load($filepath))
                    $output .= Color::GREEN . "[Reacram] \"$filename\" is loaded into \"$name\"\n";
                else
                    $output .= Color::RED . "[Reacram] \"$filename\" is NOT loaded into \"$name\"\n";
                break;
            case "name":
                $name = strtolower(array_shift($args));
                if (isset($this->nameTask[$issuer])) {
                    $output .= "[Reacram][Error] Wait! You have to touch the reactor to name \"" . $this->nameTask[$issuer] . "\"\n";
                    break;
                }
                if (isset($this->reactensionManagers[$name])) {
                    $output .= "[Reacram][Error] \"$name\" has already existed\n";
                    break;
                }
                $this->nameTask[$issuer] = $name;
                $output .= "[Reacram] Touch the reactor to name \"$name\"\n";
                break;
            case "link":
                $name = strtolower(array_shift($args));
                if (isset($this->linkTask[$issuer])) {
                    $output .= "[Reacram][Error] Wait! You have to touch the chest which links with \"" . $this->linkTask[$issuer] . "\"\n";
                    break;
                }
                if (!isset($this->reactensionManagers[$name])) {
                    $output .= "[Reacram][Error] \"$name\" is not found\n";
                    break;
                }
                $this->linkTask[$issuer] = $name;
                $output .= "[Reacram] Touch the chest to link \"$name\"\n";
                break;
            case "run":
                $name = array_shift($args);
                if (!isset($this->reactensionManagers[$name])) {
                    $output .= "[Reacram][Error] \"$name\" is not found\n";
                    break;
                }
                if (!$this->reactensionManagers[$name]->isLoaded()) {
                    $output .= "[Reacram][Error] \"$name\" is not set the program to run\n";
                    break;
                }
                $this->reactensionManagers[$name]->activate();
                $output .= "[Reacram] \"$name\" is running now\n";
                break;
            case "stop":
                $name = array_shift($args);
                if (!isset($this->reactensionManagers[$name])) {
                    $output .= "[Reacram][Error] \"$name\" is not found\n";
                    break;
                }
                if ($this->reactensionManagers[$name]->isRun()) {
                    $this->reactensionManagers[$name]->stop();
                    $output .= "[Reacram] \"$name\" is stopped\n";
                } else {
                    $output .= "[Reacram][Error] \"$name\" is not running\n";
                }
                break;
            default:
                $output .= "[Reacram][Error] \"/reacram $subCmd\" dose not exist\n";
                break;
        }
        $sender->sendMessage($output);
    }

    public function onBlockTouch(PlayerInteractEvent $evt) {

        $player = $evt->getPlayer();
        $block = $evt->getBlock();
        if ($block->getID() == Block::NETHER_REACTOR) {
            if (isset($this->nameTask[$player->getName()])) {
                $name = $this->nameTask[$player->getName()];
                if ($this->getReacramByPosition($block) === false) {
                    $this->reactensionManagers[$name] = new ReactensionManager($block->x, $block->y, $block->z, $block->getLevel());
                    $player->sendMessage("[Reacram] The reactor has been named \"$name\"");
                } else {
                    $player->sendMessage("[Reacram][Error] The reactor is already named");
                }
                unset($this->nameTask[$player->getName()]);
            } else {
                if ((($manager = $this->getReacramByPosition($block)) !== false) and ( $manager->isLinked())) {
                    $tile = $evt->getBlock()->getLevel()->getTileById($manager->linkedChest);
                }
            }
        }
        if ($block->getID() === Block::CHEST) {
            if (isset($this->linkTask[$player->getName()])) {
                $name = $this->linkTask[$player->getName()];
                $this->reactensionManagers[$name]->link($block);
                unset($this->linkTask[$player->getName()]);
                $player->sendMessage("[Reacram] Successfully link the chest to \"$name\"");
                return false;
            }
        }
    }

    public function onBlockBreak(BlockBreakEvent $evt) {

        $player = $evt->getPlayer()->getName();
        $block = $evt->getBlock();
        if ($block->getID() === Block::CHEST) {
            foreach ($this->reactensionManagers as $name => $reactensionManager) {

                if (
                        $block->x === $reactensionManager->linkedChest->x and
                        $block->y === $reactensionManager->linkedChest->y and
                        $block->z === $reactensionManager->linkedChest->z) {
                    $player->sendMessage("[Reacram][Error] You can't break the chest");
                    $evt->setCancelled();
                }
            }
        }
    }

    private function getReacramByPosition(Block $pos) {
        foreach ($this->reactensionManagers as $reactensionManager) {
            $reactensionManager->sync();
            if (($reactensionManager->x === $pos->x) and 
                    ( $reactensionManager->y === $pos->y) and 
                    ( $reactensionManager->z === $pos->z) and 
                    ( $reactensionManager->level->getName() ===
                    $pos->getLevel()->getName())) {
                return $reactensionManager;
            }
        }
        return false;
    }

    public function saveReacramData() {
        $this->getLogger()->info("[Reacram] Saving...");
        foreach ($this->reactensionManagers as $name => $reactensionManager) {
            $reactensionManager->sync();
            $chestx = null;
            $chesty = null;
            $chestz = null;
            if ($reactensionManager->isLinked()) {
                $chestx = $reactensionManager->linkedChest->x;
                $chesty = $reactensionManager->linkedChest->y;
                $chestz = $reactensionManager->linkedChest->z;
            }
            $this->config->set($name, array(
                "x" => $reactensionManager->x,
                "y" => $reactensionManager->y,
                "z" => $reactensionManager->z,
                "levelname" => $reactensionManager->level->getName(),
                "levelid" => $reactensionManager->level->getID(),
                "filepath" => $reactensionManager->filepath,
                "islinked" => $reactensionManager->isLinked(),
                "chestx" => $chestx,
                "chesty" => $chesty,
                "chestz" => $chestz
            ));
        }
        $this->config->save();
        $this->getLogger()->info("[Reacram] Done");
    }

    public function runReacram() {
        foreach ($this->reactensionManagers as $reactensionManager) {
            if ($reactensionManager->isRun()) {
                $reactensionManager->run();
            }
        }
    }

    public function onDisable() {
        $this->saveReacramData();
    }

}

class Tasker extends PluginTask {

    public function onRun($currentTick) { 
        $this->getOwner()->runReacram();
    }

}

class ReactensionManager {

    private $run, $server;
    public $x, $y, $z, $filepath;
    /* @var $level Level */
    public $level;
    /* @var $reactension Reactension */
    public $reactension;
    /* @var $linkedChest ChestBlock */
    public $linkedChest;

    /**
     * 
     * @param type $x
     * @param type $y
     * @param type $z
     * @param Level $level
     * @param type $filepath
     * @param Block $chest
     * @param bool $run
     */
    public function __construct($x, $y, $z, Level $level, $filepath = "", $chest = false, $run = false) {
        $this->x = $x;
        $this->y = $y;
        $this->z = $z;
        $this->level = $level;
        /* @var $reactension Reactension */
        $this->reactension = false;
        $this->filepath = $filepath;
        $this->linkedChest = $chest;
        $this->run = $run;
        $this->server = Server::getInstance();
        if ($this->filepath !== "") $this->load($filepath);
    }

    public function load($filepath) {

        if (file_exists($filepath) and ( substr($filepath, -4) === ".php")) {
            $className = __NAMESPACE__ . "\\" . basename($filepath, ".php");
            if (!class_exists($className, false)) {
                require_once $filepath;
            }

            $class = new $className($this, $this->x, $this->y, $this->z, $this->level);
            if (is_subclass_of($class, __NAMESPACE__ . '\Reactension', false)) {
                $this->filepath = $filepath;
                $this->reactension = $class;
                $this->reactension->init();
                return true;
            } else {
                unset($class);
            }
        }

        return false;
    }

    public function activate() {
        $this->run = true;
    }

    public function stop() {
        $this->run = false;
    }

    public function run() {
        if ($this->isLoaded())
            $this->reactension->run();
    }

    public function link(ChestBlock $chest) {
        $this->linkedChest = $chest;
    }

    public function isLinked() {
        return ($this->linkedChest === false) ? false : true;
    }

    public function isLoaded() {
        return ($this->reactension === false) ? false : true;
    }

    public function isRun() {
        return $this->run;
    }

    public function sync() {
        if ($this->isLoaded()) {
            $info = $this->reactension->sync();
            $this->x = $info['x'];
            $this->y = $info['y'];
            $this->z = $info['z'];
        }
    }

}

abstract class Reactension {

    public $manager, $x, $y, $z, $level;

    public function __construct(ReactensionManager $manager, $x, $y, $z, Level $level) {
        $this->manager = $manager;
        $this->x = $x;
        $this->y = $y;
        $this->z = $z;
        $this->level = $level;
    }

    abstract public function init();

    abstract public function run();

    public function sendToChest(Item $target) {
        if ($this->manager->isLinked()) {
            /* @var $tile TileChest */
            $tile = $this->manager->level->getTile($this->manager->linkedChest);

            if (!($tile instanceof TileChest)) {
                return false;
            } else {
                $inventory = $tile->getRealInventory();
            }
            for ($i = 0; $i < $tile->getSize(); $i++) {
                /* @var $item Item */
                $item = $inventory->getItem($i);
                if ($item->getID() === Block::AIR) {
                    $inventory->setItem($i, $target);
                    return true;
                } else if (($item->getID() === $target->getID()) and ( ($item->getMaxStackSize() - $item->count) >= $target->count)) {
                    $target->count += $item->count;
                    $inventory->setItem($i, $target);
                    return true;
                }
            }
        } else
            return false;
    }

    public function move($x, $y, $z) {


        $this->level->setBlock(new Vector3($this->x, $this->y, $this->z), Block::get(Block::AIR), true, true);
        $this->level->setBlock(new Vector3($x, $y, $z), Block::get(Block::NETHER_REACTOR), true, true);
        $this->x = $x;
        $this->y = $y;
        $this->z = $z;
    }

    public function sync() {
        return array("x" => $this->x, "y" => $this->y, "z" => $this->z);
    }

}
