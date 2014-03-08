<?php

/*
 __PocketMine Plugin__
name=Reacram
description=Make your reactor programmable
version=1.0.1
author=MinecrafterJPN
class=Reacram
apiversion=12
*/

class Reacram implements Plugin
{
	const REACTENSION_PATH = "reactension/";

	private $api, $config, $nameTask, $linkTask, $reactensionManagers, $executedReacrams;

	public function __construct(ServerAPI $api, $server = false)
	{
		$this->api = $api;
	}

	public function init()
	{
		@mkdir($this->api->plugin->configPath($this).self::REACTENSION_PATH);
		$this->config = new Config($this->api->plugin->configPath($this)."reacrams.yml", CONFIG_YAML);
		$this->nameTask = array();
		$this->linkTask = array();
		$this->reactensionManagers = array();
		$this->executedReacrams = array();
		$this->load();
		$this->api->console->register("reacram", "Reacram command - help, list, name, load, link, run, stop", array($this, "commandHandler"));
		$this->api->addHandler("player.block.touch", array($this, "eventHandler"));
		$this->api->event("server.close", array($this, "saveReacramData"));
		$this->api->schedule(20, array($this, "runReacram"), array(), true);	// 1sec
	}

	private function load()
	{
		foreach ($this->config->getAll() as $name => $info) {
			$level = $this->api->level->get($info['levelname']);
			$chest = false;
			if ($info['islinked']) {
				$chest = $level->getBlock(new Vector3($info['chestx'], $info['chesty'], $info['chestz']));
			}
			$this->reactensionManagers[$name] = new ReactensionManager($info['x'], $info['y'], $info['z'], $level, $info['filepath'], $chest);
		}
	}

	public function CommandHandler($cmd, $args, $issuer, $alias)
	{
		$output = "";
		$subCmd = strtolower(array_shift($args));

		if (!($issuer instanceOf Player)) {
			$output .= "[Reacram][Error] Must be run in the world\n";
			return $output;
		}

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
				foreach (scandir($this->api->plugin->configPath($this).self::REACTENSION_PATH) as $filename) {
					if (substr($filename, -4) === ".php") {
						$output .= "[Reacram] ".basename($filename, ".php"). "\n";
					}
				}
				break;

			case "name":
				$name = strtolower(array_shift($args));
				if (isset($this->nameTask[$issuer->username])) {
					$output .= "[Reacram][Error] Wait! You have to touch the reactor to name \"".$this->nameTask[$issuer->username]."\"\n";
					break;
				}
				if (isset($this->reactensionManagers[$name])) {
					$output .= "[Reacram][Error] \"$name\" has already existed\n";
					break;
				}
				$this->nameTask[$issuer->username] = $name;
				$output .= "[Reacram] Touch the reactor to name \"$name\"\n";
				break;
			
			case "load":
				$name = strtolower(array_shift($args));
				if (!isset($this->reactensionManagers[$name])) {
					$output .= "[Reacram][Error] \"$name\" is not found\n";
					break;
				}
				$filename = array_shift($args);
				$filenameWithExt = (substr($filename, -4) === ".php") ? $filename : $filename.".php";
				$filepath = $this->api->plugin->configPath($this).self::REACTENSION_PATH.$filenameWithExt;
				if (!file_exists($filepath)) {
					$output .= "[Reacram][Error] \"$filename\" is not found\n";
					break;
				}
				$this->reactensionManagers[$name]->load($filepath);
				$output .= "[Reacram] \"$filename\" is loaded into \"$name\"\n";
				break;

			case "link":
				$name = strtolower(array_shift($args));
				if (isset($this->linkTask[$issuer->username])) {
					$output .= "[Reacram][Error] Wait! You have to touch the chest which links with \"".$this->linkTask[$issuer->username]."\"\n";
					break;
				}
				if (!isset($this->reactensionManagers[$name])) {
					$output .= "[Reacram][Error] \"$name\" is not found\n";
					break;
				}
				$this->linkTask[$issuer->username] = $name;
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
		return $output;
	}

	public function eventHandler($data, $event)
	{	
		if ($data['target']->getID() === NETHER_REACTOR) {
			if (isset($this->nameTask[$data['player']->username])) {
				$name = $this->nameTask[$data['player']->username];
				if ($this->getReacramByPosition($data['target']) === false) {
					$this->reactensionManagers[$name] = new ReactensionManager($data['target']->x, $data['target']->y, $data['target']->z, $data['target']->level);
					$this->sendTo("[Reacram] The reactor has been named \"$name\"", $data['player']->username);
				} else {
					$this->sendTo("[Reacram][Error] The reactor is already named", $data['player']->username);
				}
				unset($this->nameTask[$data['player']->username]);
			} elseif ((($manager = $this->getReacramByPosition($data['target'])) !== false) and ($manager->isLinked())) {
				$tile = $this->api->tile->get($manager->linkedChest)->openInventory($data['player']);
			}
		}
		if ($data['target']->getID() === CHEST) {
			if (isset($this->linkTask[$data['player']->username])) {
				$name = $this->linkTask[$data['player']->username];
				$this->reactensionManagers[$name]->link($data['target']);
				unset($this->linkTask[$data['player']->username]);
				$this->sendTo("[Reacram] Successfully link the chest to \"$name\"", $data['player']->username);
				return false;
			} else if ($data['type'] === "break") {
				foreach ($this->reactensionManagers as $name => $reactensionManager) {
					if ($data['target']->x === $reactensionManager->linkedChest->x and $data['target']->y === $reactensionManager->linkedChest->y and $data['target']->z === $reactensionManager->linkedChest->z) {
						$this->sendTo("[Reacram][Error] You can't break the chest", $data['player']->username);
						return false;
					}
				}
			}
			
		}
	}

	public function runReacram()
	{
		foreach ($this->reactensionManagers as $reactensionManager) {
			if ($reactensionManager->isRun()) {
				$reactensionManager->run();
			}
		}
	}

	public function saveReacramData($data, $event)
	{
		console("[Reacram] Saving...");
		foreach ($this->reactensionManagers as $name => $reactensionManager) {
			$reactensionManager->sync();
			$chestx = null;
			$chesty = null;
			$chetsz = null;
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
				"filepath" => $reactensionManager->filepath,
				"islinked" => $reactensionManager->isLinked(),
				"chestx" => $chestx,
				"chesty" => $chesty,
				"chestz" => $chestz
			));

		}
		$this->config->save();
		console("[Reacram] Done");
	}

	private function getReacramByPosition(Position $pos)
	{
		foreach ($this->reactensionManagers as $reactensionManager) {
			$reactensionManager->sync();
			if (($reactensionManager->x === $pos->x) and ($reactensionManager->y === $pos->y) and ($reactensionManager->z === $pos->z) and ($reactensionManager->level->getName() === $pos->level->getName())) {
				return $reactensionManager;
			}
		}
		return false;
	}

	private function sendTo($msg, $username)
	{
		$this->api->chat->sendTo(false, $msg, $username);
	}

	public function __destruct()
	{
	}
}

class ReactensionManager
{
	private $run;
	public $x, $y, $z, $level, $filepath, $reactension, $linkedChest;

	public function __construct($x, $y, $z, Level $level, $filepath = "", $chest = false) 
	{
		$this->x = $x;
		$this->y = $y;
		$this->z = $z;
		$this->level = $level;
		$this->reactension = false;
		$this->filepath = $filepath;
		$this->linkedChest = $chest;
		$this->run = false;
		if ($this->filepath !== "") $this->load($filepath);
	}

	public function load($filepath)
	{
		if (file_exists($filepath) and (substr($filepath, -4) === ".php")) {
			$className = basename($filepath, ".php");
			if (!class_exists($className)) {
				require_once($filepath);
			}

			if (is_subclass_of($className, "Reactension")) {
				$this->filepath = $filepath;
				$this->reactension = new $className($this, $this->x, $this->y, $this->z, $this->level);
				$this->reactension->init();
				return true;
			}
		}
		return false;
	}

	public function activate()
	{
		$this->run = true;
	}

	public function stop()
	{
		$this->run = false;
	}

	public function run()
	{
		if ($this->isLoaded()) $this->reactension->run();
	}

	public function link(ChestBlock $chest)
	{
		$this->linkedChest = $chest;
	}

	public function isLinked()
	{
		return ($this->linkedChest === false) ? false : true;
	}

	public function isLoaded()
	{
		return ($this->reactension === false) ? false : true;
	}

	public function isRun()
	{
		return $this->run;
	}

	public function sync()
	{
		if ($this->isLoaded()) {
			$info = $this->reactension->sync();
			$this->x = $info['x'];
			$this->y = $info['y'];
			$this->z = $info['z'];
		}
	}

}

abstract class Reactension
{
	public $manager, $x, $y, $z, $level;

	public function __construct(ReactensionManager $manager, $x, $y, $z, Level $level)
	{
		$this->manager = $manager;
		$this->x = $x;
		$this->y = $y;
		$this->z = $z;
		$this->level = $level;
	}

	abstract public function init();

	abstract public function run();

	public function sendToChest(Item $target)
	{
		if ($this->manager->isLinked()) {
			$tile = ServerAPI::request()->api->tile->get($this->manager->linkedChest);
			for ($i = 0; $i < CHEST_SLOTS; $i++) {
				$item = $tile->getSlot($i);
				if ($item->getID() === AIR) {
					$tile->setSlot($i, $target);
					return true;
				} elseif (($item->getID() === $target->getID()) and (($item->getMaxStackSize() - $item->count) >= $target->count)) {
					$target->count += $item->count;
					$tile->setSlot($i, $target);
					return true;
				}
			}
		} else {
			return false;
		}
	}

	public function move($x, $y, $z)
	{
		$this->level->setBlock(new Vector3($this->x, $this->y, $this->z), ServerAPI::request()->api->block->get(AIR));
		$this->level->setBlock(new Vector3($x, $y, $z), ServerAPI::request()->api->block->get(NETHER_REACTOR));
		$this->x = $x;
		$this->y = $y;
		$this->z = $z;
	}

	public function sync()
	{
		return array("x" => $this->x, "y" => $this->y, "z" => $this->z);
	}
}