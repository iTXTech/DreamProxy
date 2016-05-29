<?php

/*
 *
 *  ____            _        _   __  __ _                  __  __ ____
 * |  _ \ ___   ___| | _____| |_|  \/  (_)_ __   ___      |  \/  |  _ \
 * | |_) / _ \ / __| |/ / _ \ __| |\/| | | '_ \ / _ \_____| |\/| | |_) |
 * |  __/ (_) | (__|   <  __/ |_| |  | | | | | |  __/_____| |  | |  __/
 * |_|   \___/ \___|_|\_\___|\__|_|  |_|_|_| |_|\___|     |_|  |_|_|
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * @author PocketMine Team
 * @link http://www.pocketmine.net/
 *
 *
*/

/**
 * Minecraft: PE multiplayer protocol implementation
 */
namespace synapse\network\protocol\mcpe;


interface Info{

	/**
	 * Actual Minecraft: PE protocol version
	 */
	const CURRENT_PROTOCOL = 70;
	const ACCEPTED_PROTOCOLS = [45, 46, 60];

	const LOGIN_PACKET = 0x8f;
	const DISCONNECT_PACKET = 0x91;
	const BATCH_PACKET = 0x92;
	const CHANGE_DIMENSION_PACKET = 0xc1;
	const PLAYER_LIST_PACKET = 0xc3;
}











