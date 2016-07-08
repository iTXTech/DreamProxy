<?php

/*
 *
 *  _____   _____   __   _   _   _____  __    __  _____
 * /  ___| | ____| |  \ | | | | /  ___/ \ \  / / /  ___/
 * | |     | |__   |   \| | | | | |___   \ \/ /  | |___
 * | |  _  |  __|  | |\   | | | \___  \   \  /   \___  \
 * | |_| | | |___  | | \  | | |  ___| |   / /     ___| |
 * \_____/ |_____| |_|  \_| |_| /_____/  /_/     /_____/
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * @author iTX Technologies
 * @link https://itxtech.org
 *
 */

namespace synapse;

use synapse\event\player\PlayerLoginEvent;
use synapse\event\player\PlayerLogoutEvent;
use synapse\event\player\PlayerTransferEvent;
use synapse\event\Timings;
use synapse\network\protocol\mcpe\BatchPacket;
use synapse\network\protocol\mcpe\DataPacket;
use synapse\network\protocol\mcpe\DisconnectPacket;
use synapse\network\protocol\mcpe\Info;
use synapse\network\protocol\mcpe\PlayerListPacket;
use synapse\network\protocol\spp\PlayerLoginPacket;
use synapse\network\protocol\spp\PlayerLogoutPacket;
use synapse\network\protocol\spp\RedirectPacket;
use synapse\network\SourceInterface;
use synapse\utils\UUID;
use synapse\utils\TextFormat;

class Player{
	/** @var DataPacket */
	private $cachedLoginPacket = null;
	private $name;
	private $ip;
	private $port;
	private $clientId;
	private $randomClientId;
	private $protocol;
	/** @var UUID */
	private $uuid;
	/** @var SourceInterface */
	private $interface;
	/** @var Client */
	private $client;
	/** @var Server */
	private $server;
	private $rawUUID;
	private $isFirstTimeLogin = true;
	private $lastUpdate;
	private $closed = false;

	public function __construct(SourceInterface $interface, $clientId, $ip, int $port){
		$this->interface = $interface;
		$this->clientId = $clientId;
		$this->ip = $ip;
		$this->port = $port;
		$this->name = "Unknown";
		$this->server = Server::getInstance();
		$this->lastUpdate = microtime(true);
	}

	public function getClientId(){
		return $this->randomClientId;
	}

	public function getRawUUID(){
		return $this->rawUUID;
	}

	public function getServer() : Server{
		return $this->server;
	}

	public function handleDataPacket(DataPacket $pk){
		if($this->closed){
			return;
		}
		$timings = Timings::getPlayerReceiveDataPacketTimings($pk);

		$timings->startTiming();

		$this->lastUpdate = microtime(true);

		switch($pk::NETWORK_ID){
			case Info::BATCH_PACKET:
				if($this->cachedLoginPacket == null){
					/** @var BatchPacket $pk */
					$this->getServer()->getNetwork()->processBatch($pk, $this);
				}else{
					$this->redirectPacket($pk->buffer);
				}
				break;
			case Info::LOGIN_PACKET:
				$this->cachedLoginPacket = $pk->buffer;
			//	$this->name = $pk->username //fix 0.15.2 join server
				$this->uuid = UUID::fromString($pk->clientUUID);
				$this->rawUUID = $this->uuid->toBinary();
				$this->randomClientId = $pk->clientId;
				$this->protocol = $pk->protocol;

				$this->server->getLogger()->info($this->getServer()->getLanguage()->translateString("synapse.player.logIn", [
					TextFormat::AQUA . $this->name . TextFormat::WHITE,
					$this->ip,
					$this->port,
					TextFormat::GREEN . $this->randomClientId . TextFormat::WHITE,
				]));

				$c = $this->server->getMainClients();
				if(count($c) > 0){
					$clientHash = array_rand($c);
				}else{
					$clientHash = "";
				}

				$this->server->getPluginManager()->callEvent($ev = new PlayerLoginEvent($this, "Plugin Reason", $clientHash));
				if($ev->isCancelled()){
					$this->close($ev->getKickMessage());
					break;
				}

				if(!isset($this->server->getClients()[$ev->getClientHash()])){
					$this->close("Synapse Server: " . TextFormat::RED . "No server online!");
					break;
				}

				$this->transfer($this->server->getClients()[$ev->getClientHash()]);
				break;
			default:
				$this->redirectPacket($pk->buffer);
		}
		$timings->stopTiming();
	}

	public function redirectPacket(string $buffer){
		$packet = new RedirectPacket();
		$packet->uuid = $this->uuid;
		$packet->direct = false;
		$packet->mcpeBuffer = $buffer;
		$this->client->sendDataPacket($packet);
	}

	public function getIp(){
		return $this->ip;
	}

	public function getPort() : int{
		return $this->port;
	}

	public function getUUID(){
		return $this->uuid;
	}

	public function getName() : string{
		return $this->name;
	}
	
	public function onUpdate($currentTick){
		if((microtime(true) - $this->lastUpdate) >= 5 * 60){//5 minutes timeout
			$this->close("timeout");
		}
	}

	public function removeAllPlayer(){
		$pk = new PlayerListPacket();
		$pk->type = PlayerListPacket::TYPE_REMOVE;
		foreach($this->client->getPlayers() as $p){
			$pk->entries[] = [$p->getUUID()];
		}
		$this->sendDataPacket($pk);
	}

	public function transfer(Client $client, bool $needDisconnect = false){
		$this->server->getPluginManager()->callEvent($ev = new PlayerTransferEvent($this, $client, $needDisconnect));
		if(!$ev->isCancelled()){
			if($this->client instanceof Client and $needDisconnect){
				$pk = new PlayerLogoutPacket();
				$pk->uuid = $this->uuid;
				$pk->reason = "Player has been transferred";
				$this->client->sendDataPacket($pk);

				$this->client->removePlayer($this);

				$this->removeAllPlayer();
			}
			$this->client = $ev->getTargetClient();
			$this->client->addPlayer($this);
			$pk = new PlayerLoginPacket();
			$pk->uuid = $this->uuid;
			$pk->address = $this->ip;
			$pk->port = $this->port;
			$pk->isFirstTime = $this->isFirstTimeLogin;
			$pk->cachedLoginPacket = $this->cachedLoginPacket;
			$this->client->sendDataPacket($pk);

			$this->isFirstTimeLogin = false;

			$this->server->getLogger()->info("{$this->name} has been transferred to {$this->client->getIp()}:{$this->client->getPort()}");
		}
	}

	public function sendDataPacket(DataPacket $pk, $direct = false, $needACK = false){
		$timings = Timings::getPlayerSendDataPacketTimings($pk);
		$timings->startTiming();
		$this->interface->putPacket($this, $pk, $needACK, $direct);
		$timings->stopTiming();
	}

	public function close(string $reason = "Generic reason"){
		if(!$this->closed){
			$this->server->getPluginManager()->callEvent(new PlayerLogoutEvent($this));
			$pk = new DisconnectPacket();
			$pk->message = $reason;
			$this->sendDataPacket($pk, true);

			$this->closed = true;

			if($this->client instanceof Client){
				$pk = new PlayerLogoutPacket();
				$pk->uuid = $this->uuid;
				$pk->reason = $reason;
				$this->client->sendDataPacket($pk);
				$this->client->removePlayer($this);
			}

			$this->server->getLogger()->info($this->getServer()->getLanguage()->translateString("synapse.player.logOut", [
				TextFormat::AQUA . $this->getName() . TextFormat::WHITE,
				$this->ip,
				$this->port,
				$this->getServer()->getLanguage()->translateString($reason)
			]));

			$this->getServer()->removePlayer($this);
		}
	}
}
