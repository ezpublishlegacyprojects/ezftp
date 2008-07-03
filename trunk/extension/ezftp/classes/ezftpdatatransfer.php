<?php
class eZFTPDataTransfer
{
    /**
     * Constructor
     */
    public function eZFTPDataTransfer( &$client )
    {
        $this->client = &$client;
        $this->command = $client->command;
        $this->done = false;
    }
    
    public function interact()
    {
        switch( $this->client->command )
        {
            case "RETR":
                if ( $data = $this->client->io->read() )
                {
                    $this->send( $data );
                    $this->done = true;
                }
                else
                {
                    $this->done = true;
                }
                break;
                
            case "STOR":
                if ( ( $buffer = $this->read() ) !== false )
                {
                    if ( !strlen($buffer) )
                    {
                        $this->done = true;
                    }
                    $this->client->io->writeTemporaryFile( $buffer );
                }
                else
                {
                    $this->done = true;
                }
                break;

            case "LIST":
                $list = $this->client->io->ls();
                if ( count( $list ) == 0 )
                {
                    $this->send( '' );
                    $this->eol();
                    $this->done = true;
                }
                else
                { 
                    foreach( $list as $info )
                    {
                        $formattedList = sprintf("%-11s%-2s%-15s%-15s%-10s%-13s".$info['name'], $info['perms'], "1", $info['owner'], $info['group'], $info['size'], $info['time']);
                        $this->send( $formattedList );
                        $this->eol();
                    }
                    $this->done = true;
                }
                break;
        }
        
        if ( $this->done )
        {
            if ( $this->client->command == "STOR" )
            {
                $this->client->io->createContentFromTemporaryFile();
                $this->client->io->closeTemporaryFile();
            }
            $this->close();
        }
    }
    
    function close()
    {
        if ( !$this->client->pasv )
        {
            if ( is_resource( $this->dataFsp ) )
                fclose( $this->dataFsp );
            $this->dataFsp = false;
        }
        else
        {
            socket_close( $this->dataConnection );
            $this->dataConnection = false;
        }
    }
    
    function open()
    {
        if ( $this->client->pasv )
        {
            if ( !$connection = @socket_accept( $this->client->dataSocket  ) )
            {
                eZDebug::writeError( 'Can not get connection' , 'eZFTPDataTransfer::open' );
                return false;
            }

            if ( !socket_getpeername( $connection, $peerIp, $peerPort ) )
            {
                eZDebug::writeError( 'Can not get peer' , 'eZFTPDataTransfer::open' );
                $this->dataConnection = false;
                return false;
            }
            else
            {
                eZDebug::writeDebug( "Client connected ($peerIp:$peerPort)" , 'eZFTPDataTransfer::open' );
            }

            $this->dataConnection = &$connection;

        }
        else
        {
            $fsp = fsockopen( $this->client->dataAddr, $this->client->dataPort, $errno, $errstr, 30 );

            if ( !$fsp )
            {
                eZDebug::writeError( 'Could not connect to client' , 'eZFTPDataTransfer::open' );
                return false;
            }

            $this->dataFsp = $fsp;
        }

        return true;
    }

    function read()
    {
        if ( $this->client->pasv )
        {
            return socket_read( $this->dataConnection, 1024 );
        }
        else
        {
            return fread( $this->dataFsp, 1024 );
        }
    }
    
    function send( $str )
    {   
//        if ( $this->client->transferType == "A" )
//        {
//            $str = str_replace( "\r", '', $str );
//            $str = str_replace( "\n", eZServer::CRLF, $str );
//        }
        
        if ( $this->client->pasv )
        {
            socket_write( $this->dataConnection, $str, strlen( $str ) );
        }
        else
        {
            fputs( $this->dataFsp, $str );
        }
    }

    function eol()
    {
        $eol = ( $this->client->transferType == "A" ) ? eZFTPServer::CRLF : eZFTPServer::LF;
        $this->send( $eol );
    }
    
    public function isDone()
    {
    	return $this->done;
    }
    
    //eZFTPClient objects
    private $client;
    private $command;
    
    //is transfert done?
    private $done;
    
    //data socket resource
    private $dataConnection;
    private $dataFsp;
}
?>