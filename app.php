<?php

    require "Clank" . DIRECTORY_SEPARATOR . "clank.php";

    class MyWebSocket extends Clank\Clank {
        /*
         |  LISTENER :: ON OPEN
         |  @since  0.1.0
         */
        public function onOpen($client, array $headers): void {
            echo "open";
        }

        /*
         |  LISTENER :: ON MESSAGE
         |  @since  0.1.0
         */
        public function onMessage($client, string $message): void {
            echo "message";

            $this->send($client, "PHP received: " . $message);
        }

        /*
         |  LISTENER :: ON CLOSE
         |  @since  0.1.0
         */
        public function onClose($client): void {
            echo "close";
        }

        /*
         |  LISTENER :: ON ERROR
         |  @since  0.1.0
         */
        public function onError($client, array $headers = []): void {
            echo "error";
        }
    }

    $socket = new MyWebSocket([
        "domain"    => "sockets.ls",
        "port"      => 4000,
    ]);
    $socket->run();
