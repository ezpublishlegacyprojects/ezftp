<?php

include_once( 'extension/ezftp/classes/ezftpinout.php' );
include_once( 'extension/ezftp/classes/ezftpdatatransfer.php' );

class eZFTPClient
{

    /**
     * Constructor
     */
    public function eZFTPClient( $connection, $id, $settings )
    {
        $this->settings = $settings;
        $this->user = null;
        $this->id = $id;
        $this->dataTransfer = false;
        $this->connection = $connection;
        $this->pasv = false;
        $this->cwd = '/';
        $this->buffer = '';
        $this->command      = '';
        $this->parameter    = '';
        $this->transferType = 'A';
        $this->utf8Enabled = false;
        $this->rnfr == false;
    }
    
    public function run()
    {
        if ( !is_resource( $this->connection ) )
        {
            eZDebug::writeError( 'Socket connection is not a valid' , 'eZFTPClient::run' );
            return false;
        }      
        
        if ( !socket_getpeername( $this->connection, $addr, $port ) )
        {
        	eZDebug::writeError( 'Socket type is not AF_INET  AF_INET6 neither AF_UNIX' , 'eZFTPClient::run' );
            return false;
        }
        $this->addr = $addr;
        $this->port = $port;
        
        $this->send( 220, $this->settings['WelcomeMessage'] );
        
        return true;
    }

    public function interact()
    {
        //capture empty command
        if ( !strlen(  trim( $this->buffer ) ) )
        {
            $this->send( 500, '?' );
            return true;
        }
        else
        {
            $matches = explode ( eZFTPServer::SP , $this->buffer, 2 );
            $this->command = isset( $matches[0] )?strtoupper( $matches[0] ):'';
            $this->parameter = isset( $matches[1] )?rtrim( $matches[1] ):'';
            
            //capture strange command
            if ( $this->command == '' || preg_match( '/\W/'  , $this->command ) )
            {
                $this->send( 500, '?' );
                return true;
            }
            
            //commands which do not need to be logged in
            switch ( $this->command )
            {
                case 'QUIT':
                    $this->cmdQuit();
                    return false;
                    break;
                case 'USER':
                    $this->cmdUser();
                    return true;
                    break;
                case 'PASS':
                    $this->cmdPass();
                    return true;
                    break;
                case 'TYPE':
                    $this->cmdType();
                    return true;
                    break;
                case 'SYST':
                    $this->cmdSyst();
                    return true;
                    break;
                case 'NOOP':
                    $this->cmdNoop();
                    return true;
                    break;
                case 'HELP':
                    $this->cmdHelp();
                    return true;
                    break;
                case 'FEAT':
                    $this->cmdFeat();
                    return true;
                    break;
                case 'OPTS':
                    $this->cmdOpts();
                    return true;
                    break;
            }                    

            if ( !$this->isLoggedIn() )
            {
                $this->send( 530, "You aren't logged in" );
                return true;
            }

            //commands which need to be logged in
            switch ( $this->command )
            {        
                case 'LIST':
                case 'NLST':
                    $this->cmdList();
                    return true;
                    break;
                case 'PASV':
                    $this->cmdPasv();
                    break;
                case 'PORT':
                    $this->cmdPort();
                    break;
                case 'PWD':
                    $this->cmdPwd();
                    break;
                case 'CWD':
                    $this->cmdCwd();
                    break;
                case 'CDUP':
                    $this->cmdUp();
                    break;
                case 'RETR':
                    $this->cmdRetr();
                    break;
                case 'STOR':
                    $this->cmdStor();
                    break;
                case 'DELE':
                    $this->cmdDele();
                    break;
                case 'RMD':
                    $this->cmdRmd();
                    break;
                case 'MKD':
                    $this->cmdMkd();
                    break;
                case 'RNFR':
                    $this->cmdRnfr();
                    break;
                case 'RNTO':
                    $this->cmdRnto();
                    break;
//                case 'APPE':
//                    $this->cmdAppe();
//                    break;
//                case 'SITE':
//                    $this->cmdSite();
//                    break;
                default:    
                    $this->send( 502, 'Command not implemented' );
            }
            
            return true;
        }
    }
     
    /**
     * send a FTP formated message to client
     * @param integer $code
     * @param string|array $message
     */  
    public function send( $code, $message, $multiLineFormat = 1 )
    {
        //simple line
        if ( !is_array( $message ) && strlen( $message ) > 0 )
        {
        	$output = $code . ' ' . $message . eZFTPServer::CRLF;
        }
        //this is an array -> multi-line
        else
        {
        	$count = count( $message );
            if ( $count > 0 )
            {
                if ( $count == 1 )
                {
                	$output = $code . ' ' . $message[0] . eZFTPServer::CRLF;
                }
                // RFC959 multi-line format
                elseif ( $multiLineFormat == 2 )
                {
                    
                    $output =  $code . '-' . $message[0] . eZFTPServer::CRLF;
                    for ( $i = 1; $i < $count - 1 ; $i++)
                    {
                        if ( is_numeric( substr ( $message[$i], 0, 1 ) ) )
                        {
                            $output .= ' ';
                        }
                        $output .=  $message[$i] . eZFTPServer::CRLF;
                    }
                    $output .=  $code . ' ' . $message[$count - 1] . eZFTPServer::CRLF;
                    socket_write( $this->connection, $output );
                }
                //FEAT command multi-line format
                elseif ( $multiLineFormat == 3 )
                {
                	$output = $code . '-' . $message[0] . eZFTPServer::CRLF;
                    for ( $i = 1; $i < $count - 1 ; $i++)
                    {
                        $output .=  ' ' . $message[$i] . eZFTPServer::CRLF;
                    }
                    $output .=  $code . ' ' . $message[$count - 1] . eZFTPServer::CRLF;
                } 
                // common multi-line format
                else
                {
                        $output = '';
                        for ( $i = 0; $i < $count - 1 ; $i++)
                        {
                            $output .=  $code . '-' . $message[$i] . eZFTPServer::CRLF;
                        }
                        $output .=  $code . ' ' . $message[$count - 1] . eZFTPServer::CRLF;
                }      
            }
            else
            {
            	eZdebug::writeError( 'message array is empty', 'eZFTP::send' );
            }
        }
        socket_write( $this->connection, $output );
    }
    
    /**
     * disconnect from client
     */
    private function disconnect()
    {
        if ( is_resource( $this->connection ) )
            @socket_close( $this->connection );

        if ( $this->pasv )
        {
            if ( is_resource( $this->dataConnection ) )
                @socket_close( $this->dataConnection );
                
            if ( is_resource( $this->dataSocket ) )
                @socket_close( $this->dataSocket );
            
            $pool = &$GLOBALS['eZFTPDataPortPool'];    
            unset( $pool[$this->dataPort] );
           
        }
    }

    /**
     * TYPE ftp command
     * syntax:
     *   TYPE <SP> <type-code> <CRLF>
     *   <type-code> ::= A [<sp> <form-code>]
     *                 | E [<sp> <form-code>]
     *                 | I
     *                 | L <sp> <byte-size>
     */
    private function cmdType()
    {
        $type = strtoupper( $this->parameter );
        if ( strlen( $this->parameter ) == 0 )
        {
            $message = array( 'Missing argument',
                              'A(scii) I(mage)',
                              $this->typeStatusMessage() );
                                   
            $this->send( 501, $message );
        }
        else if ( $type != 'A' && $type != 'I' )
        {
            $message = array( 'Unknown TYPE: ' . $this->parameter,
                              $this->typeStatusMessage() );
                                   
            $this->send( 501, $message );  
        }
        else
        {
            $this->transferType = $type;
            $this->send( 200, $this->typeStatusMessage() );
        }
    }
    
    private function typeStatusMessage()
    {
    	$message = 'TYPE is now ';
        if ( $this->transferType == 'A' )
            $message .= 'ASCII mode';
        else
             $message .= '8-bit binary';
        return $message;
    }

    /**
     * CDUP ftp command
     * Change to Parent Directory
     * syntax:
     *   CDUP <CRLF>
     */
    private function cmdUp()
    {
       
        $splittedCwd = preg_split( "/\//", $this->cwd, -1, PREG_SPLIT_NO_EMPTY );
        
        if ( count( $splittedCwd ) )
        {
            array_pop( $splittedCwd );
            $terminate = ( count( $splittedCwd ) > 0 ) ? "/" : "";
            $this->parameter = "/" . implode( "/", $splittedCwd ) . $terminate;
        }
        else
        {
            $this->parameter = $this->cwd;
        }

        $this->cmdCwd();
    }

    /**
     * CWD ftp command
     * Change working directory
     * syntax:
     *   CWD  <SP> <pathname> <CRLF>
     *   <pathname> ::= <string>
     */
    private function cmdCwd()
    {
        $path = $this->parameter;
        
        //empty path=root path
        if ( !strlen( $path ) )
        {
            $path = '/';
        }
        
        $path = $this->cleanPath( $path );
        
        $this->io->setPath( $path );

        if ( !$this->io->exists() )
        {
            $this->send( 550, "Can't change directory to $path: No such file or directory" );
        }
        elseif ( !$this->io->canRead() )
        {
            $this->send( 550, "Can't change directory to $path: Permission denied" );
        }
        elseif ( $this->io->type() != eZFTPInOut::TYPE_DIRECTORY )
        {
        	$this->send( 550, "Can't change directory to $path: Not a directory" );
        }
        else
        {
        	$this->cwd = $path;
            $this->send( 250, 'Ok. Current directory is ' . $this->cwd );
        }
    }
    
    /**
     * HELP ftp command
     * syntax:
     *   HELP [<SP> <string>] <CRLF>
     */
    private function cmdHelp()
    {
        $messageArray = array( 'The following commands are recognized',
                               'CDUP',
                               'CWD',
                               'HELP',
                               'LIST',
                               'NOOP',
                               'PASS',
                               'PORT',
                               'PWD',
                               'QUIT',
                               'SYST',
                               'TYPE',
                               'USER',
                               'QUIT',
                               'HELP command successful' );
        $this->send( 214, $messageArray );
    }

    /**
     * LIST ftp command
     * syntax:
     *   LIST [<SP> <pathname>] <CRLF>
     *   <pathname> ::= <string>
     */
    private function cmdList()
    {        
        //remove arg in paramater like -al
        $path = preg_replace( '/^\-[a-zA-Z]+\s?/' ,'' , $this->parameter );
        $path = $this->cleanPath( $path );
        
        $this->io->setPath( $path );
        
        if ( !$this->io->exists() )
        {
             $this->send( 550, "Can't open $path: No such file or directory" );
        }
        elseif ( !$this->io->canRead() )
        {
            $this->send( 550, "Can't open $path: Permission denied" );
        }
        else
        {
            $this->dataTransfer = new eZFTPDataTransfer( &$this );
    
            if ( !$this->dataTransfer->open() )
            {
                $this->send( 425, "Can't open data connection" );
                $this->dataTransfer->close();
                return;
            }
            
            $this->send( 150, 'Opening data connection' );
            
            /*
            while ( !$this->dataTransfer->isDone() )
                $this->dataTransfer->interact();
    
            $this->onDataTransferFinished();*/
        }
    }

    /**
     * NOOP ftp command
     * syntax:
     *   PASS <CRLF>
     */
    private function cmdNoop()
    {
        $this->send( 200, 'Zzz...' );
    }

    /**
     * PASS ftp command
     * specify the user's password
     * syntax:
     *   PASS <SP> <password> <CRLF>
     *   <password> ::= <string>
     */
    private function cmdPass()
    {
        if ( $this->isLoggedIn() )
        {
            $this->send( 530, "We can't do that in the current session" );
            return;
        }

        if ( !$this->login )
        {
            $this->login = "";
            $this->send( 530, 'Please tell me who you are' );
            return;
        }

        $user = false;
        include_once( 'kernel/classes/datatypes/ezuser/ezuserloginhandler.php' );
        $ini =& eZINI::instance( 'site.ini' );
        if ( $ini->hasVariable( 'UserSettings', 'LoginHandler' ) )
        {
            $loginHandlers = $ini->variable( 'UserSettings', 'LoginHandler' );
        }
        else
        {
            $loginHandlers = array( 'standard' );
        }

        foreach ( array_keys ( $loginHandlers ) as $key )
        {
            $loginHandler = $loginHandlers[$key];
            $userClass =& eZUserLoginHandler::instance( $loginHandler );
            $user = $userClass->loginUser( $this->login, $this->parameter );
            if ( get_class( $user ) == 'ezuser' )
                break;
        }
        
        if ( get_class( $user ) != 'eZUser' )
        {
            $this->send( 530, 'Login authentication failed' );
        }
        else
        {
            $this->send( 230, 'User ' . $this->login . ' logged in from ' . $this->addr );
            $this->user = $user;
            $this->userHomeDir = '/';
            $this->io = new eZFTPInOut( $this );
        }
    }

    /**
     * PASV ftp command
     * requests the server-DTP to "listen" on a data port 
     * syntax:
     *   PASV <CRLF>
     */
    private function cmdPasv()
    {
        $pool = &$GLOBALS['eZFTPDataPortPool'];

        if ( $this->pasv )
        {
            if ( is_resource( $this->dataConnection ) )
                socket_close( $this->dataConnection );

            if ( is_resource( $this->dataSocket ) )
                socket_close( $this->dataSocket );

            $this->dataConnection = false;
            $this->dataSocket = false;

            if ( $this->dataPort )
                unset( $pool[$this->dataPort] );
        }

        $this->pasv = true;

        $lowPort = $this->settings['LowPort'];
        $highPort = $this->settings['HighPort'];

        $try = 0;

        if ( ( $socket = socket_create( AF_INET, SOCK_STREAM, 0 ) ) < 0 )
        {
            $this->send( 425, "Can't open data connection" );
            return;
        }

        // reuse listening socket address 
        if ( ! @socket_set_option( $socket, SOL_SOCKET, SO_REUSEADDR, 1 ) )
        {
            $this->send( 425, "Can't open data connection" );
            return;
        }

        for ( $port = $lowPort; $port <= $highPort && $try < 4; $port++ )
        {
            if ( ! array_key_exists( $port, $pool ) )
            {
                $try++;

                $c = socket_bind( $socket, $this->settings['ListenAddress'], $port );

                if ( $c >= 0 )
                {
                    $pool[$port] = 1;
                    break;
                }
            }
        }

        if ( ! $c )
        {
            $this->send( 452, "Can't open data connection" );
            return;
        }

        socket_listen( $socket );

        $this->dataSocket = &$socket;
        $this->dataPort = $port;

        $p1 = $port >>  8;
        $p2 = $port & 0xff;

        $tmp = str_replace( ".", ",", $this->settings['ListenAddress'] );
        $this->send( 227, "Entering Passive Mode ({$tmp},{$p1},{$p2})" );
    }

    /**
     * PWD ftp command
     * syntax:
     *   PWD  <CRLF>
     */
    private function cmdPwd()
    {
        $this->send( 257, "\"" . $this->cwd . "\" is current your location" );
    }

    /**
     * QUIT ftp command
     * Logout
     * syntax:
     *   QUIT  <CRLF>
     */
    private function cmdQuit()
    {
        $this->send( 221, "Disconnected from FTP Server. Have a nice day" );
        $this->disconnect();
    }

    /**
     * SYST ftp command
     * syntax:
     *   SYST  <CRLF>
     */
    private function cmdSyst()
    {
        $this->send( 215, "UNIX Type: L8" );
    }

    /**
     * USER ftp command
     * specify the user
     * syntax:
     *   USER <SP> <username> <CRLF>
     *   <username> ::= <string>
     */
    private function cmdUser()
    {
        if ( $this->isLoggedIn() )
        {
            $this->send( 530, "You're already logged in" );
            return;
        }

        $this->user = null;
        $this->login = $this->parameter;
        $this->send( 331, 'User ' . $this->login . ' OK. Password required' );
    }

    /**
     * PORT ftp command
     * specify the data port to be used in data connection.
     * syntax:
     *   PORT <SP> <host-port> <CRLF>
     *   <host-port> ::= <host-number>,<port-number>
     *   <host-number> ::= <number>,<number>,<number>,<number>
     *   <port-number> ::= <number>,<number>
     *   <number> ::= any decimal integer 1 through 255
     */
    private function cmdPort()
    {        
        $data = explode( ",", $this->parameter );

        if ( count( $data ) != 6 )
        {
            $this->send( 500 , 'Wrong number of Parameters' );
            return;
        }

        $p2 = array_pop($data);
        $p1 = array_pop($data);

        $port = ($p1 << 8) + $p2;

        foreach($data as $ip_seg)
        {
            if (! is_numeric($ip_seg) || $ip_seg > 255 || $ip_seg < 0)
            {
                $this->send( 500 , "Bad IP address " . implode( ".", $data ) . "." );
                return;
            }
        }

        $ip = implode(".", $data);

        if (! is_numeric($p1) || ! is_numeric($p2) || ! $port)
        {
            $this->send( 500, '500 Bad Port number' );
            return;
        }

        $this->dataAddr = $ip;
        $this->dataPort = $port;

        $this->send( 200 , 'PORT command successful' );
    }

    /**
     * FEAT ftp command
     * syntax:
     *   FEAT <CRLF>
     */
    private function cmdFeat()
    {       
        $features = array( 'Features:',
                           'UTF8',
                           'End' );
        $this->send( 211 , $features, 3 );
    }

    /**
     * OPTS ftp command
     * syntax:
     *   OPTS <SP> <command-name> [ <SP> <command-options> ] <CRLF>
     *   <command-name> ::= any FTP command which allows option setting
     *   <command-options> ::= format specified by individual FTP command
     */
    private function cmdOpts()
    {
        $matches = explode ( eZFTPServer::SP , $this->parameter, 2 );
        $name = isset( $matches[0] )?strtoupper( $matches[0] ):'';
        $options = isset( $matches[1] )?rtrim( $matches[1] ):'';
            
        switch ( $name )
        {
            case 'UTF8':
                $this->cmdOptsUtf8( $options );
                break;
             default:
                $this->send( 501 , 'Unknown option' );
        }
    }
    
    /**
     * OPTS UTF8 ftp command
     * syntax:
     *   OPTS <SP> UTF8 <status> <CRLF>
     *   <status> ::= ON|OFF
     */
    private function cmdOptsUtf8( $options )
    {
    	$status = strtoupper( $options );
        if ( strlen( $this->parameter ) == 0 )
        {
            $message = array( 'Missing argument',
                              'ON OFF',
                              $this->typeStatusMessage() );
                                   
            $this->send( 501, $message );
        }
        elseif ( $status == 'ON' )
        {
        	$this->utf8Enabled = true;
            $this->send( 200, $this->utf8StatusMessage() );
        }
        elseif ( $status == 'OFF' )
        {
        	$this->utf8Enabled = false;
            $this->send( 200, $this->utf8StatusMessage() );
        }
        else
        {
            $message = array( 'Unknown argument for UTF8: ' . $this->parameter, $this->utf8StatusMessage() );
                                   
            $this->send( 501, $message );  
        }
    }
    
    /**
     * RETR ftp command
     * syntax:
     *   RETR <SP> <pathname> <CRLF>
     *   <pathname> ::= <string>
     */
    private function cmdRetr()
    {
        $path = $this->cleanPath( $this->parameter );
        
        $this->io->setPath( $path );

        if ( !$this->io->exists() )
        {
             $this->send( 550, "Can't open $path: No such file or directory" );
        }
        elseif ( !$this->io->canRead() )
        {
            $this->send( 550, "Can't open $path: Permission denied" );
        }
        elseif ( $this->io->type() != eZFTPInOut::TYPE_FILE )
        {
            $this->send( 550, "Can't open $path: Not a file" );
        }
        else
        {
    	    $this->dataTransfer = new eZFTPDataTransfer( &$this );
            
            if ( !$this->dataTransfer->open() )
            {
                $this->send( 425, "Can't open data connection");
                return;
            }
            $this->send( 150, 'Accepted data connection' );
        }
    }
    
    /**
     * STOR ftp command
     * syntax:
     *   STOR <SP> <pathname> <CRLF>
     *   <pathname> ::= <string>
     */
    private function cmdStor()
    {
    	$path = $this->cleanPath( $this->parameter );
        
        //$parentPath = '/fre/Media/';
        
        $this->io->setPath( $path );

        if ( $this->io->exists() )
        {
            if ( $this->io->type() != eZFTPInOut::TYPE_FILE )
            {
                $this->send( 550, "Can't write $path: Not a file" );
            }
            elseif ( !$this->io->canModify() )
            {
                $this->send( 550, "Can't write $path: Permission denied" );
            }
        }
        //TODO check if we can write in parent
        
        $append = ($this->command == "APPE");
    
        if (! $this->io->openTemporaryFile( $append ) )
        {
            $this->send( 550, "Can't write $path: Permission denied" );
            return;
        }
        
        $this->send( 150, "File status okay; opening connection");
        
        $this->dataTransfer = new eZFTPDataTransfer( &$this );
        
        if ( !$this->dataTransfer->open() )
        {
            $this->send( 425, "Can't open data connection");
            return;
        }
    }
    
    /**
     * DELE ftp command
     * syntax:
     *   DELE <SP> <pathname> <CRLF>
     *   <pathname> ::= <string>
     */
    private function cmdDele()
    {
        $path = $this->cleanPath( $this->parameter );
        
        $this->io->setPath( $path );

        if ( !$this->io->exists() )
        {
            $this->send( 550, "Can't delete $path: File does not exist" );
        }
        elseif ( $this->io->type() != eZFTPInOut::TYPE_FILE )
        {
            $this->send( 550, "Can't delete $path: Not a file" );
        }
        elseif ( !$this->io->canDelete() )
        {
            $this->send( 550, "Can't delete $path: Permission denied" );
        }
        elseif ( !$this->io->delete() )
        {
            $this->send( 550, "Couldn't delete file" );
        }
        else
        {
            $this->send( 250, "DELE command successfull" );
        }
    }
    
    
    /**
     * RMD ftp command
     * syntax:
     *   RMD <SP> <pathname> <CRLF>
     *   <pathname> ::= <string>
     */
    private function cmdRmd()
    {
        $path = $this->cleanPath( $this->parameter );
        
        $this->io->setPath( $path );

        if ( !$this->io->exists() )
        {
            $this->send( 550, "Can't delete $path: Directory does not exist" );
        }
        elseif ( $this->io->type() != eZFTPInOut::TYPE_DIRECTORY )
        {
            $this->send( 550, "Can't delete $path: Not a directory" );
        }
        elseif ( !$this->io->canDelete() )
        {
            $this->send( 550, "Can't delete $path: Permission denied" );
        }
        elseif ( !$this->io->delete() )
        {
            $this->send( 550, "Couldn't delete directory" );
        }
        else
        {
            $this->send( 250, "RMD command successfull" );
        }
    }
    
    /**
     * MKD ftp command
     * syntax:
     *   MKD <SP> <pathname> <CRLF>
     *   <pathname> ::= <string>
     */
    private function cmdMkd()
    {
        $path = $this->cleanPath( $this->parameter );
        
        $this->io->setPath( $path );

        if ( $this->io->exists() )
        {
            $this->send( 550, "Can't create $path: Directory exist" );
        }
        elseif ( !$this->io->canCreate() )
        {
            $this->send( 550, "Can't create $path: Permission denied" );
        }
        elseif ( !$this->io->mkdir() )
        {
            $this->send( 550, "Couldn't create directory" );
        }
        else
        {
            $this->send( 250, "MKD command successfull" );
        }
    }
    
    /**
     * RNFR ftp command
     * syntax:
     *   RNFR <SP> <pathname> <CRLF>
     *   <pathname> ::= <string>
     */
    private function cmdRnfr()
    {        
        $path = $this->cleanPath( $this->parameter );
        
        $this->io->setPath( $path );

        if ( !$this->io->exists() )
        {
            $this->send( 553, "Can't rename $path: File doesn't exist" );
        }
        else
        {
            //$this->rnfr = $this->io->getObject();
            $this->rnfr = $path;
            $this->send( 350, "RNFR command successful" );
        }
    }
    
    /**
     * RNTO ftp command
     * syntax:
     *   RNTO <SP> <pathname> <CRLF>
     *   <pathname> ::= <string>
     */
    private function cmdRnto()
    {
        $path = $this->cleanPath( $this->parameter );
        
        $io = new eZFTPInOut( $this );
        $io->setPath( $path );
        
        if ( !$this->rnfr )
        {
            $this->send( 553, "Can't rename to $path: need an RNFR command" );
        }
        //only rename
        elseif ( dirname( $path ) == dirname( $this->rnfr ) )
        {
        	if ( !$this->io->canModify() )
            {
                $this->send( 553, "Can't rename $path: Permission denied" );
            }
            else
            {
            	$this->io->rename( basename( $path ) );
                $this->send( 250, "RNTO command successful");
            }
        }
// TODO implement move
//        //move
//        elseif ( !$io->canCreate() )
//        {
//            $this->send( 550, "Can't rename $path: Permission denied" );
//        }
//        elseif ( $this->io->move( $io->getParentNode() ) )
//        {
//            $this->send( 250, "RNTO command successful");
//        }
        else
        {
            $this->send( 553, "Requested action not taken");
        }
        
        $this->rnfr == false;
    }
    
    private function utf8StatusMessage()
    {
        $message = 'UTF8 is now ';
        if ( $this->utf8Enabled == true )
            $message .= 'ON';
        else
             $message .= 'OFF';
        return $message;
    }
    
    private function isLoggedIn()
    {
    	return ( $this->user != null );
    }
    
    /**
     * clear the buffer
     */
    public function resetBuffer()
    {
    	$this->buffer = '';
    }
    
    /**
     * add a message to the buffer
     */
    public function addToBuffer( $buffer )
    {
        $this->buffer .= $buffer;
    }
    
    /**
     * 
     */
    public function onDataTransferFinished()
    {
        $this->dataTransfer = false;
        $this->send( 226, 'Transfer complete' );
    }

    /**
     * @return boolean true if the client has a data transfer open
     */
    public function hasDataTransfer()
    {
        return ( $this->dataTransfer !== false );
    }
    
    public function isUtf8Enabled()
    {
    	return $this->utf8Enabled;
    }
    
    private function cleanPath( $path )
    {        
        $path = preg_replace( '/[\/]+/', '/', $path );
        
        //absolute path
        if ( substr( $path, 0, 1 ) == "/" )
        {
            //we are on root dir
            if ( strlen( $path ) == 1 )
            {
                $path = "/";
            }
            else
            {
                $path = rtrim( $path, '/' );
            }
        }
        //relative path
        else
        {
            $path =  rtrim( $this->cwd, '/' ) . '/' . rtrim( $path, '/' );
        }
        
        return $path;
    }

    private $id;
    private $settings;

    public $user;
    private $login;
    private $userHomeDir;
    var $buffer;
    var $transferType;
    public $connection;
    var $addr;
    var $port;
    var $pasv;
    public $dataTransfer;
    var $dataAddr;
    var $dataPort;
    var $dataSocket;
    var $dataConnection;
    var $dataFsp;
    var $command;
    var $parameter;
    var $io;
    var $cwd;
    private $utf8Enabled;
    private $rnfr;
    
}
?>