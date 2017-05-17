<?php

namespace Kloudspeaker\Setup;

class Installer {
	private $versions = NULL;
	private $pluginVersions = [];

	public function __construct($c) {
		$this->container = $c;
		$this->logger = $c->logger;
	}

	public function initialize() {
		$this->container->commands->register('installer:check', [$this, 'checkInstallation']);
		$this->container->commands->register('installer:perform', [$this, 'performInstallation']);
		$this->container->commands->register('system:config', [$this, 'systemConfig']);
	}

	public function checkInstallation() {
		$this->logger->info("Checking installation");

		$result = [
			"system" => [
				"site" => FALSE,
				"configuration" => FALSE,
				"database_configuration" => FALSE,
				"database_connection" => FALSE,
				"database_version" => NULL,
				"installation" => FALSE
			],
			"plugins" => [],
			"available" => []
		];

		$this->logger->info("Checking configuration...");
		if (!$this->container->configuration->getSystemInfo()["site_folder_exists"]) {
			$this->logger->error("Kloudspeaker site folder does not exist");
			$result["available"][] = ["id" => "system:config"];
			return $result;
		}
		$result["system"]["site"] = TRUE;
		if (!$this->container->configuration->getSystemInfo()["config_exists"]) {
			$this->logger->error("Kloudspeaker configuration does not exist");
			$result["available"][] = ["id" => "system:config"];
			return $result;
		}
		$result["system"]["configuration"] = TRUE;

		$this->logger->info("Checking database configuration...");
		if (!$this->isDatabaseConfigured()) {
			$this->logger->error("Database not configured");
			$result["available"][] = ["id" => "system:config"];
			return $result;
		}
		$result["system"]["database_configuration"] = TRUE;

		$this->logger->info("Checking database connection...");
		$conn = $this->container->dbfactory->checkConnection();
		if (!$conn["connection"]) {
		    $this->logger->error("Cannot connect to database: ".$conn["reason"]);
		    $result["system"]["database_connection_error"] = $conn["reason"];
		    return $result;
		}
		$result["system"]["database_connection"] = TRUE;

		$db = $this->container->db;

		$this->logger->info("Checking database tables...");
		if ($db->tableExists("parameter")) {
			$this->logger->info("Old installation exists, checking database version");
			$result["system"]["installation"] = TRUE;

			$latestVersion = $this->getLatestVersion();

			try {
				$installedVersion = $this->getSystemInstalledVersion();

				if (!$installedVersion or $installedVersion == NULL) {
					$this->logger->info("No database version found");
					
					$migrateFromVersion = $db->select('parameter', ['name', 'value'])->where('name', 'version')->done()->execute()->firstValue('value');

					if (!$migrateFromVersion or $migrateFromVersion == NULL) {
						$this->logger->info("Installation exists but no migration version found");
						return $result;
					}

					$this->logger->info("Old version detected: $migrateFromVersion");
					if (!\Kloudspeaker\Utils::strStartsWith($migrateFromVersion, "2_7_")) {
						$this->logger->error("Cannot migrate from this version, update to last 2.7.x version");
						return $result;
					}
					$result["available"][] = ["id" => "system:migrate", "from" => $migrateFromVersion, "to" => $latestVersion];

					$result["available"] = array_merge($result["available"], $this->getAvailablePluginActions());
					return $result;
				}

				$this->logger->info("Installed version: $installedVersion");

				$latest = ($installedVersion == $latestVersion["id"]);
				if (!$latest) {
					$result["available"][] = ["id" => "system:update", "from" => $installedVersion, "to" => $latestVersion];
				}

				$result["available"] = array_merge($result["available"], $this->getAvailablePluginActions());

				return $result;
			} catch (Exception $e) {
				$this->logger->info("Unable to resolve installed version: ".$e->getMessage());
				return $result;
			}
		} else {
			// no table exist, assume empty database
			$result["available"][] = ["id" => "system:install", "to" => $this->getLatestVersion()];
		}

		return $result;
	}

	public function getSystemInstalledVersion() {
		return $this->container->db->select('parameter', ['name', 'value'])->where('name', 'database')->done()->execute()->firstValue('value');
	}

	public function getPluginInstalledVersion($id) {
		return $this->container->db->select('parameter', ['name', 'value'])->where('name', "plugin_".$id."_version")->done()->execute()->firstValue('value');
	}

	public function getVersionInfo() {
		if ($this->versions == NULL)
			$this->versions = json_decode($this->readFile('/setup/db/migrations.json'), TRUE);
		return $this->versions;
	}

	public function getPluginVersionInfo($id) {
		$plugin = $this->container->plugins->get($id);
		if (array_key_exists($id, $this->pluginVersions))
			return $this->pluginVersions[$id];
		$this->pluginVersions[$id] = json_decode(file_get_contents($plugin["root"].'/db/migrations.json'), TRUE);
		return $this->pluginVersions[$id];
	}

	public function getLatestVersion() {
		$ver = $this->getVersionInfo();	//make sure versions are read
		if (count($ver["versions"]) == 0) throw new \Kloudspeaker\KloudspeakerException("No version info found");
		return $ver["versions"][count($ver["versions"])-1];
	}

	private function getAvailablePluginActions() {
		$result = [];
		foreach ($this->container->plugins->get() as $p) {
			$pr = $this->getAvailablePluginAction($p["id"]);
			if ($pr != NULL) $result[] = $pr;
		}
		return $result;
	}

	public function systemConfig($cmds, $opts) {
		$this->logger->info("System config: cmd=".\Kloudspeaker\Utils::array2str($cmds).", opts=".\Kloudspeaker\Utils::array2str($opts));

		$values = isset($opts["config"]) ? $opts["config"] : [];
		if (!isset($values["db.dsn"])) throw new \Kloudspeaker\KloudspeakerException("Missing required config value: db.dsn");
		if (!isset($values["db.user"])) throw new \Kloudspeaker\KloudspeakerException("Missing required config value: db.user");
		if (!isset($values["db.password"])) throw new \Kloudspeaker\KloudspeakerException("Missing required config value: db.password");

        $conn = $this->container->dbfactory->checkConnection(["dsn" => $values["db.dsn"], "user" => $values["db.user"], "password" => $values["db.password"]]);
        if (!$conn["connection"]) {
            $this->container->logger->error("Cannot connect to database: ".$conn["reason"]);
            return [ "success" => FALSE, "error" => "invalid_db_config", "details" => $conn["reason"]];
        }
        $this->createConfiguration($values);
        return [ "success" => TRUE ];
	}

	public function performInstallation($cmds, $opts) {		
		$check = $this->checkInstallation();
		$this->logger->info("Perform install: cmd=".\Kloudspeaker\Utils::array2str($cmds).", opts=".\Kloudspeaker\Utils::array2str($opts).",actions=".\Kloudspeaker\Utils::array2str($check["available"]));

		if (count($check["available"]) == 0) {
			return [
				"check" => $check,
				"reason" => "No actions available",
				"success" => FALSE
			];
		}

		$this->logger->info("Performing installation");

		if ($check["available"][0]["id"] == "system:config") {
			$result = $this->systemConfig([], $opts);
			if ($result["success"]) $this->logger->info("System configured, rerun installer to reload configuration");
			return $result;
		}

		$this->container->db->startTransaction();
		$result = [];
		try {
			foreach ($check["available"] as $action) {
				$result[$action["id"]] = NULL;

				$actionResult = [];
				if ($action["id"] == "system:install") $actionResult = $this->installSystem();
				else if ($action["id"] == "system:migrate") $actionResult = $this->migrateSystem();
				//TODO plugin install/update/migrate
				else throw new \Kloudspeaker\KloudspeakerException("Invalid install action: ".$action["id"]);

				$result[$action["id"]] = $actionResult;
			}
			$this->container->db->commit();
			$result["success"] = TRUE;
		} catch (Exception $e) {
			$this->container->db->rollback();
			$result["success"] = FALSE;
			$result["reason"] = $e->getMessage();
			$result["exception"] = $e;
		} catch (Throwable $e) {
			$this->container->db->rollback();
			$result["success"] = FALSE;
			$result["reason"] = $e->getMessage();
			$result["exception"] = $e;
		} 
		
		return $result;
	}

	public function createConfiguration($values) {
		$siteFolder = $this->container->configuration->getSiteFolderLocation();

		if (!$this->container->configuration->getSystemInfo()["site_folder_exists"]) {
			$this->logger->info("Site folder does not exist, creating: $siteFolder");

			if (!is_writable($this->container->configuration->getInstallationRoot()))
				throw new \Kloudspeaker\KloudspeakerException("Cannot create site folder, installation folder not writable");
			mkdir($siteFolder);
		}
		$configFile = $this->container->configuration->getConfigurationFileLocation();
		if (!$this->container->configuration->getSystemInfo()["config_exists"]) {
			if (!touch($configFile))
				throw new \Kloudspeaker\KloudspeakerException("Cannot create configuration file: $configFile");
		}
		$this->container->configuration->setValues($values);
		$this->container->configuration->store();
	}

	private function installSystem() {
		$db = $this->container->db;
		$db->script($this->readFile('/setup/db/create.sql'));

		// add version info
		$latestVersion = $this->getLatestVersion();
		$db->insert('parameter', ['name' => 'database', 'value' => $latestVersion["id"]])->execute();

		// install all plugins
		foreach ($this->getAllInstallablePlugins() as $plugin) {
			# code...
		}
	}

	private function updateSystem() {

	}

	private function migrateSystem() {
		return;
		$db = $this->container->db;

		$fromVersion = $db->select('parameter', ['name', 'value'])->where('name', 'version')->done()->execute()->firstValue('value');

		if (!$fromVersion or $fromVersion == NULL) {
			$this->logger->info("No migration version found");
			return FALSE;
		}

		$this->logger->info("Migrating from version: $fromVersion");
		if (!\Kloudspeaker\Utils::strStartsWith($fromVersion, "2_7_")) {
			$this->logger->info("Cannot migrate from this version, update to last 2.7.x version");
			return FALSE;
		}

		$db->script($this->readFile('/setup/db/migrate_from_2.sql'));

		// add version info
		$latestVersion = $this->getLatestVersion();
		$db->insert('parameter', ['name' => 'database', 'value' => $latestVersion["id"]])->execute();

		return TRUE;
	}

	public function getLatestPluginVersion($id) {
		$versionInfo = $this->getPluginVersionInfo($id);

		if (count($versionInfo["versions"]) == 0) throw new \Kloudspeaker\KloudspeakerException("No plugin version info found");
		return $versionInfo["versions"][count($versionInfo["versions"])-1];
	}

	public function getAvailablePluginAction($id) {
		$plugin = $this->container->plugins->get($id);

		if (!isset($plugin["db"]) or !$plugin["db"]) return NULL;
		$current = $this->getPluginInstalledVersion($id);
		$latest = $this->getLatestPluginVersion($id);

		$this->logger->info("Current installed version: $current, latest $latest");

		if ($current == NULL) return ["id" => "plugin:install", "plugin" => $id, "to" => $latest["id"]];
		if ($current == $latest["id"]) return NULL;

		if (strpos($current, "_") != NULL) return ["id" => "plugin:migrate", "plugin" => $id, "from" => $current, "to" => $latest["id"]];
		return ["id" => "plugin:update", "plugin" => $id, "from" => $current, "to" => $latest["id"]];
	}

	public function installPlugin($id) {
		$action = $this->getAvailablePluginAction($id);
		if ($action == NULL) return;
	}

	private function readFile($path) {
		return file_get_contents($this->container->configuration->getInstallationRoot() . $path);
	}

	public function isDatabaseConfigured() {
		$c = $this->container->configuration;

		$this->logger->debug("is configured".$c->has("db.dsn")."/".$c->has("db.user")."/".$c->has("db.password"));
		
		if (!$c->has("db.dsn")) return FALSE;
		if (!$c->has("db.user")) return FALSE;
		if (!$c->has("db.password")) return FALSE;
		return TRUE;
	}
}