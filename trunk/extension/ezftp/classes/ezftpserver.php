<?php

include_once( 'extension/ezftp/classes/ezftpclient.php' );

class eZFTPServer
{
    const CR = "\r";
    const LF = "\n";
    const CRLF = "\r\n";
    const SP = ' ';
    
    /**
     * Constructor
     */
    public function eZFTPServer()
    {
        $ini =& eZINI::instance( 'ezftp.ini' );
        $this->settings = $ini->group( 'eZFTPSettings' );   
        $this->socket = false;
        $this->clients = array();
    }
  
    public function run()
    {
    	// assign listening socket 
        if ( !( $this->socket = @socket_create( AF_INET, SOCK_STREAM, 0 ) ) )
        {
            $this->socketError();
            return false;
        }

        // reuse listening socket address 
        if ( !@socket_setopt( $this->socket, SOL_SOCKET, SO_REUSEADDR, 1 ) )
        {
            $this->socketError();
            return false;
        }

        // set socket to non-blocking 
        if ( !@socket_set_nonblock( $this->socket ) )
        {
            $this->socketError();
            return false;
        }

        // bind listening socket to specific address/port 
        if ( $this->settings['DynamicIP'] == 'enabled' )
        {
            $IPAddress = $this->getIPAddress();
            if ( !$IPAddress )
                return false;

            $this->settings['ListenAddress'] = $IPAddress;
        }
        
        if ( !@socket_bind( $this->socket, $this->settings['ListenAddress'], $this->settings['ListenPort'] ) )
        {
            $this->socketError();
            return false;
        }

        // listen on listening socket
        if ( !socket_listen( $this->socket ) )
        {
            $this->socketError();
            return false;
        }

        // set initial vars and loop until $abort is set to true
        $abort = false;
        
        while ( !$abort )
        {
            // sockets we want to pay attention to
            $socketList = array_merge( array( 'server' => $this->socket ), $this->getClientConnections() );
            
            $tmpSocketList = $socketList;
            
            // loop through sockets and check for data transfers
            foreach( $tmpSocketList as $identifier => $socket )
            {
                $clientID = array_search( $socket, $socketList );
                if ( !$clientID || $clientID == 'server' )
                    continue;
                
                if ( $this->clients[$clientID]->hasDataTransfer() )
                {
                    $dataTransfer = &$this->clients[$clientID]->dataTransfer;
                    if ( $dataTransfer->isDone() )
                    {
                        $this->clients[$clientID]->onDataTransferFinished();
                    }
                    else
                    {
                        // do data transfer, and remove client from socket list for listening on the control connection
                        $dataTransfer->interact();
                        unset( $tmpSocketList[$identifier] );
                    }
                }
            }
            
            reset($tmpSocketList);
            
            if ( socket_select( $tmpSocketList, $tmpSocketListW, $tmpSocketListE, 0 ) > 0)
            {
                // loop through sockets
                foreach( $tmpSocketList as $socket )
                {
                    $name = array_search ( $socket, $socketList );

                    if ( !$name)
                    {
                        continue;
                    }
                    elseif ( $name == 'server' )
                    {
                        if ( !( $connection = socket_accept( $this->socket ) ) )
                        {
                            $this->socketError();
                            return false;
                        }
                        else
                        {
                            // add socket to client list and announce connection
                            $clientID = uniqid( 'client_' );
                            
                            $this->clients[$clientID] = new eZFTPClient( $connection, $clientID, $this->settings );
                            
                            // if MaxConnexion exceeded disconnect client
                            if ( count( $this->clients ) > $this->settings['MaxConnexion'] )
                            {
                                $this->clients[$clientID]->send( 421, 'Maximum user count reached.' );
                                $this->removeClient( $clientID );
                                continue;
                            }
                            
                            // get a list of how many connections each IP has
                            $ipPool = array();
                            foreach( $this->clients as $client )
                            {
                                $ip = $client->addr;
                                $ipPool[$ip] = ( array_key_exists( $ip, $ipPool ) ) ? $ipPool[$ip] + 1 : 1;
                            }
                            
                            // disconnect when MaxConnexionPerIP is exceeded for this client
                            if ( $ipPool[$ip] > $this->settings['MaxConnexionPerIP'] )
                            {
                                $this->clients[$clientID]->send( 421, 'Too many connections from this IP.' );
                                $this->removeClient($clientID);
                                continue;
                            }
                            
                            // everything is ok, run client
                            if ( !$this->clients[$clientID]->run() )
                            {
                                return false;
                            }
                        }
                    }
                    else
                    {
                        $clientID = $name;
                        if ( !$this->clients[$clientID]->hasDataTransfer() ) {
                            
                            // client socket has incoming data
                            if ( ( $read = @socket_read( $socket, 1024 ) ) === false || $read == '' )
                            {
                                if ($read != '')
                                {
                                    $this->socketError();
                                    return false;
                                }
    
                                // remove client from array
                                $this->removeClient( $clientID );
    
                            }
                            else
                            {
                                // only want data with a newline
                                if ( strchr( strrev( $read ), "\n" ) === false )
                                {
                                    $this->clients[$clientID]->addToBuffer( $read );
                                }
                                else
                                {
                                    $this->clients[$clientID]->addToBuffer( str_replace( array( "\r\n", "\n" ), "", $read ) );
                                    
                                    // interact with client
                                    if ( !$this->clients[$clientID]->interact() )
                                    {
                                        $this->removeClient( $clientID );
                                    }
                                    else
                                    {
                                        $this->clients[$clientID]->resetBuffer();
                                    }
                                }
                            }
                        }
                    }
                }
            }
        }
        return true;
    }
    
    private function socketError()
    {
        eZDebug::writeError( socket_strerror( socket_last_error( $this->socket) ) , 'eZFTPServer::socketError' );
        if ( is_resource( $this->socket) )
        {
            @socket_close( $this->socket );
        }
    }
    
    /**
     * \return IP of the server
     */
    private function getIPAddress()
    {
        //TODO test this function on other distribution than debian
        exec("ifconfig | grep -1 ".$this->settings['DynamicIPInterface']." | cut -s -d ' ' -f12 | grep adr | cut -d ':' -f2", $output, $return);
        if ( $return != '0' || !$output )
        {
        	eZDebug::writeError( "Couldn't get ip address... maybe you should turn off the dynamic ip detection feature..." , 'eZFTPServer::getIPAddress' );
            return false;
        }
        return rtrim( $output[0] );
    }
    
    private function getClientConnections()
    {
        $connections = array();

        foreach( $this->clients as $clientID => $client )
        {
            $connections[$clientID] = $client->connection;
        }

        return $connections;
    }
    
    private function removeClient( $clientID )
    {
        unset( $this->clients[$clientID] );
    }

   /**
    *  Sets/changes the current site(access)
    */
    static function setCurrentSite( $site )
    {
        $access = array( 'name' => $site,
                         'type' => EZ_ACCESS_TYPE_STATIC );

        $access = changeAccess( $access );

        // Clear/flush global database instance.
        $nullVar = null;
        eZDB::setInstance( $nullVar );
    }

    private $settings;
    private $socket;
    private $clients;
}
?>