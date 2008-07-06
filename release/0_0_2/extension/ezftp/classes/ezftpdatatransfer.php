<?php
class eZFTPDataTransfer
{
    /**
     * Constructor
     */
    public function eZFTPDataTransfer( &$client )
    {
        $this->client = &$client;
        $this->command = &$client->command;
        $this->io = &$client->io;
        $this->pasv = &$client->pasv;
        $this->dataSocket = &$client->dataSocket;
        $this->dataAddr = &$client->dataAddr;
        $this->dataPort = &$client->dataPort;
        $this->transferType = &$client->transferType;
        $this->done = false;
    }
    
    public function interact()
    {
        switch( $this->command )
        {
            case "RETR":
                if ( $data = $this->io->read() )
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
                    $this->io->writeTemporaryFile( $buffer );
                }
                else
                {
                    $this->done = true;
                }
                break;

            case "LIST":
                $list = $this->io->ls();
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
            if ( $this->command == "STOR" )
            {
                $this->io->createContentFromTemporaryFile();
                $this->io->closeTemporaryFile();
            }
            $this->close();
        }
    }
    
    function close()
    {
        if ( !$this->pasv )
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
        if ( $this->pasv )
        {
            if ( !$connection = @socket_accept( $this->dataSocket  ) )
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
            $fsp = fsockopen( $this->dataAddr, $this->dataPort, $errno, $errstr, 30 );

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
        if ( $this->pasv )
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
//        if ( $this->transferType == "A" )
//        {
//            $str = str_replace( "\r", '', $str );
//            $str = str_replace( "\n", eZServer::CRLF, $str );
//        }
        
        if ( $this->pasv )
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
        $eol = ( $this->transferType == "A" ) ? eZFTPServer::CRLF : eZFTPServer::LF;
        $this->send( $eol );
    }
    
    public function isDone()
    {
    	return $this->done;
    }
    
    private $client;
    private $command;
    private $io;
    private $pasv;
    private $transferType;
    private $done;
    private $dataSocket;
    private $dataAddr;
    private $dataPort;
    private $dataConnection;
    private $dataFsp;
}
?>