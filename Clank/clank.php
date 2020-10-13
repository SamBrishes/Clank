<?php

    namespace Clank;

    class Clank {
        /*
         |  WEBSOCKET GUID
         */
        const GUID = "258EAFA5-E914-47DA-95CA-C5AB0DC85B11";

        /*
         |  CLANK CONFIGURATION
         |  @type   array
         */
        public $config = [
            "domain"    => "localhost",
            "port"      => 80,
        ];

        /*
         |  SOCKET INSTANCE
         |  @type   ressource | null
         */
        public $socket;

        /*
         |  CLIENT INSTANCEs
         |  @type   array
         */
        public $clients = [ ];

        /*
         |  CONSTRUCTOR
         |  @since  0.1.0
         */
        public function __construct(array $config = []) {
            if(!extension_loaded("sockets") || !function_exists("socket_create")) {
                throw new \Exception("The PHP Sockets extension is not loaded.");
            }
            $this->config = array_merge($this->config, $config);

            // Set Server Time Limit
            set_time_limit(0);
        }

        /*
         |  ASK MESSAGE
         |  @since  0.1.0
         |
         |  @param  string  The text to mask.
         |
         |  @return string  The masked text.
         */
        public function mask(string $text): string {
            $bit = 0x80 | (0x1 & 0x0f);
            $length = strlen($text);

            if($length <= 125) {
                $header = pack("CC", $bit, $length);
            } elseif ($length > 125 && $length < 65536) {
                $header = pack("CCS", $bit, 126, $length);
            } elseif ($length >= 65536) {
                $header = pack("CCN", $bit, 127, $length);
            }
            return $header . $text;
        }

        /*
         |  UNMASK MESSAGE
         |  @since  0.1.0
         |
         |  @param  string  The byte payload from the WebSocket request.
         |
         |  @return string  The unmasked WebSocket request.
         */
        public function unmask(string $payload): string {
            $length = ord($payload[1]) & 127;
        
            if($length == 126) {
                $masks = substr($payload, 4, 4);
                $data = substr($payload, 8);
                $len = (ord($payload[2]) << 8) + ord($payload[3]);
            } elseif($length == 127) {
                $masks = substr($payload, 10, 4);
                $data = substr($payload, 14);
                $len = (ord($payload[2]) << 56) + (ord($payload[3]) << 48) +
                    (ord($payload[4]) << 40) + (ord($payload[5]) << 32) + 
                    (ord($payload[6]) << 24) +(ord($payload[7]) << 16) + 
                    (ord($payload[8]) << 8) + ord($payload[9]);
            } else {
                $masks = substr($payload, 2, 4);
                $data = substr($payload, 6);
                $len = $length;
            }
        
            $text = '';
            for ($i = 0; $i < $len; ++$i) {
                $text .= $data[$i] ^ $masks[$i%4];
            }
            return $text;
        }

        /*
         |  LISTENER :: OPEN
         |  @since  0.1.0
         |
         |  @param  ress.   The WebSocket client ressource.
         |  @param  array   The passed headers within an array.
         |
         |  @return void
         */
        public function onOpen($client, array $headers): void { }

        /*
         |  LISTENER :: MESSAGE
         |  @since  0.1.0
         |
         |  @param  ress.   The WebSocket client ressource.
         |  @param  string  The passed message as string.
         |
         |  @return void
         */
        public function onMessage($client, string $message): void { }

        /*
         |  LISTENER :: ERROR
         |  @since  0.1.0
         |
         |  @param  ress.   The WebSocket client ressource.
         |  @param  array   The passed headers within an array or an empty array.
         |
         |  @return void
         */
        public function onError($client, array $headers = []): void { }

        /*
         |  LISTENER :: CLOSE
         |  @since  0.1.0
         |
         |  @param  ress.   The WebSocket client ressource.
         |
         |  @return void
         */
        public function onClose($client): void { }

        /*
         |  HANDLE :: SEND DATA
         |  @since  0.1.0
         |
         |  @param  object  The client socket instance.
         |  @param  string  The message to send to the client.
         |
         |  @return bool    TRUE on success, FALSE on failure.
         */ 
        public function send($client, string $message) {
            $message = $this->mask($message);
            return socket_send($client, $message, strlen($message), 0) > 0;
        }

        /*
         |  HANDLE :: HANDSHAKE
         |  @since  0.1.0
         |
         |  @param  ress.   The WebSocket client ressource.
         |  @param  array   The passed headers within an array.
         |
         |  @return bool    TRUE if the header could be sent, FALSE if not.
         */
        public function handshake($client, array $headers): bool {
            $digest = base64_encode(
                sha1(($headers["Sec-WebSocket-Key"] ?? "") . self::GUID, true)
            );

            // Create Header
            $header = [
                "HTTP/1.1 101 Switching Protocols",
                "Upgrade: websocket",
                "Connection: Upgrade",
                "Sec-WebSocket-Accept: $digest",
                "Sec-WebSocket-Protocol: " . ($headers["Sec-WebSocket-Protocol"] ?? $headers["Sec-WebSocket-Version"] ?? 13),
                "Sec-WebSocket-Extensions: "
            ];

            // Send Header
            $message = implode("\r\n", $header) . "\r\n\r\n";
            return socket_send($client, $message, strlen($message), 0) >= 0;
        }

        /*
         |  RUN
         |  @since  0.1.0
         |
         |  @return void
         */
        public function run(): void {
            $this->socket = socket_create(AF_INET, SOCK_STREAM, 0);
            if(!$this->socket) {
                throw new \Exception("The socket could not be created.");
            }

            // Configure Socket
            socket_bind($this->socket, $this->config["domain"], $this->config["port"]);
            socket_listen($this->socket);
            socket_set_nonblock($this->socket);

            // Run Socket Listener
            $sockets = [$this->socket];
            while(true) {
                $read = $sockets;
                $write = null;
                $except = null;

                // Select Readable Sockets
                if(socket_select($read, $write, $except, null) < 1) {
                    continue;
                }

                // Add Client
                if(in_array($this->socket, $read)) {
                    $client = socket_accept($this->socket);

                    // Read Header
                    $data = socket_read($client, 2048);
                    $data = explode("\n", preg_replace("/\r\n/", "\n", $data));
                    $headers = [];
                    foreach($data AS $line) {
                        if(stripos($line, "GET") === 0) {
                            $headers[""] = $line;
                            continue;
                        }

                        [$key, $val] = array_pad(explode(":", $line, 2), 2, null);
                        if(!empty($key) && !empty($val)) {
                            $headers[$key] = trim($val);
                        }
                    }
                    if(empty($headers) || !isset($headers["Sec-WebSocket-Key"])) {
                        continue;
                    }
    
                    // Handshake
                    if($this->handshake($client, $headers)) {
                        $this->clients[] = $sockets[] = $client;
                        $this->onOpen($client, $headers);
                    } else {
                        $this->onError($client, $headers);
                        continue;
                    }
                }

                // Loop through all Clients
                foreach($read AS $socket) {
                    if(($buffer = @socket_read($socket, 4096)) === false) {
                        $index = array_search($socket, $this->clients);
                        if($index !== false) {
                            unset($this->clients[$index]);
                        }
                        $this->onClose($client, []);
                        continue;
                    }
                    if(!$buffer = trim($buffer)) {
                        continue;
                    }
                    $this->onMessage($socket, $this->unmask($buffer));
                }
            }
        }
    }
