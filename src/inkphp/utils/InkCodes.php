<?php

/*
 *
 *  This program is free software: you can redistribute it and/or modify
 *  it under the terms of the GNU Lesser General Public License as published by
 *  the Free Software Foundation, either version 3 of the License, or
 *  (at your option) any later version.
 *
 *  @author Ink
 *  @link https://www.github.com/ink-db
 *
 *
 */

declare(strict_types=1);

namespace inkphp\utils;

final class InkCodes
{
	// Protocol identity & defaults
	public const MAGIC = 'INK';
	public const PROTOCOL_VERSION = 1;
	public const DEFAULT_CAPABILITIES = 0;
	public const HANDSHAKE_STREAM_ID = 0;
	public const DEFAULT_PORT = 7777;
	public const MIN_PORT = 1;
	public const MAX_PORT = 65535;
	public const DEFAULT_CONNECT_TIMEOUT = 5.0;

	// Frame structure
	public const HEADER_SIZE = 18;
	public const TRAILER_SIZE = 4;
	public const MAX_PAYLOAD_SIZE = 67_108_864; // 64 MiB
	public const MAX_TABLE_NAME_LENGTH = 65535;

	// Header byte offsets
	public const HEADER_MAGIC_OFFSET = 0;
	public const HEADER_VERSION_OFFSET = 3;
	public const HEADER_TYPE_OFFSET = 4;
	public const HEADER_FLAGS_OFFSET = 5;
	public const HEADER_STREAM_ID_OFFSET = 6;
	public const HEADER_PAYLOAD_LEN_OFFSET = 10;
	public const HEADER_CRC_OFFSET = 14;

	// Flags
	public const FLAG_NONE = 0x00;
	public const FLAG_EOS = 0x01;
	public const FLAG_COMPRESSED = 0x02;

	// Handshake frames
	public const FRAME_HELLO_REQ = 0x01;
	public const FRAME_CHALLENGE = 0x02;
	public const FRAME_AUTH_RESP = 0x03;
	public const FRAME_AUTH_OK = 0x04;
	public const FRAME_AUTH_FAIL = 0x05;

	// Query / Result frames
	public const FRAME_QUERY_REQ = 0x10;
	public const FRAME_ROW_HEADER = 0x11;
	public const FRAME_ROW_DATA = 0x12;
	public const FRAME_QUERY_COMPLETE = 0x13;
	public const FRAME_REQ_CANCEL = 0x14;

	// Two-step append frames
	public const FRAME_APPEND_INIT = 0x15;
	public const FRAME_APPEND_CHALLENGE = 0x16;
	public const FRAME_APPEND_COMMIT = 0x17;

	// Streaming / flow control frames
	public const FRAME_STREAM_CHUNK = 0x20;
	public const FRAME_STREAM_ACK = 0x21;

	// Inspection / audit frames
	public const FRAME_TREE_HEAD_REQ = 0x24;
	public const FRAME_TREE_HEAD_RESP = 0x25;
	public const FRAME_PROOF_REQ = 0x26;
	public const FRAME_PROOF_RESP = 0x27;

	// Replication frames
	public const FRAME_REPL_APPEND = 0x30;
	public const FRAME_REPL_ACK = 0x31;
	public const FRAME_REPL_SYNC_REQ = 0x32;
	public const FRAME_REPL_SYNC_CHUNK = 0x33;

	// Heartbeat / liveness frames
	public const FRAME_HEARTBEAT_PING = 0x3A;
	public const FRAME_HEARTBEAT_PONG = 0x3B;

	// Authentication and cryptographic sizes
	public const AUTH_DIGEST_PREFIX = 'INK-AUTH-v1';
	public const AUTH_DIGEST_ZERO_PAD_LENGTH = 32;
	public const ED25519_SEED_SIZE = 32;
	public const ED25519_SEED_HEX_LENGTH = 64;
	public const DATABASE_ID_SIZE = 16;
	public const NONCE_SIZE = 32;
	public const SESSION_ID_SIZE = 16;
	public const LEASE_SIZE = 16;
	public const RECORD_HASH_SIZE = 32;

	// Payload layout sizes
	public const CHALLENGE_MIN_SIZE = 68;
	public const AUTH_OK_MIN_SIZE = 20;
	public const APPEND_CHALLENGE_MIN_SIZE = 68;
	public const QUERY_COMPLETE_SIZE = 16;
	public const ERROR_MIN_PAYLOAD_SIZE = 4;
	public const COLUMN_HEADER_MIN_SIZE = 2;
	public const ROW_HEADER_SIZE = 12;
	public const JSON_MAX_DEPTH = 512;

	// Protocol / Framing error codes
	public const ERR_BAD_FRAME_FORMAT = 0x0001;
	public const ERR_PAYLOAD_TOO_LARGE = 0x0002;
	public const ERR_UNSUPPORTED_VERSION = 0x0003;
	public const ERR_CRC_MISMATCH = 0x0004;
	public const ERR_STREAM_CLOSED = 0x0005;

	// Authentication error codes
	public const ERR_AUTH_REQUIRED = 0x0101;
	public const ERR_CHALLENGE_TIMEOUT = 0x0102;
	public const ERR_HANDSHAKE_FAILED = 0x0103;
	public const ERR_RATE_LIMITED = 0x0104;
	public const ERR_IP_BLOCKED = 0x0105;

	// Query execution error codes
	public const ERR_PARSE_ERROR = 0x0201;
	public const ERR_PROHIBITED_STATEMENT = 0x0202;
	public const ERR_UNKNOWN_TABLE = 0x0203;
	public const ERR_QUERY_EXEC_FAILED = 0x0204;

	// Storage / Two-step append error codes
	public const ERR_LEASE_EXPIRED = 0x0301;
	public const ERR_INVALID_SIGNATURE = 0x0302;
	public const ERR_ID_COLLISION = 0x0303;
	public const ERR_PAYLOAD_VALIDATION_ERROR = 0x0304;

	// Authorization error codes
	public const ERR_ACCESS_DENIED = 0x0403;

	// Replication error codes
	public const ERR_PEER_DESYNC = 0x0501;
	public const ERR_QUORUM_UNMET = 0x0502;
	public const ERR_NODE_UNAUTHORIZED = 0x0503;
	public const ERR_SYNC_FAILED = 0x0504;

	private function __construct()
	{
		// NOOP
	}
}
