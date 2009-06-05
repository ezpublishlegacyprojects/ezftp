<?php /* #?ini charset="iso-8859-1"?

[eZFTPSettings]

# Which IP the FTP server must listen 
ListenAddress=192.168.0.1

# Which port the FTP server must listen
ListenPort=21

# Maximum number of simultaneous connections
MaxConnexion=10

# Maximum number of simultaneous connections from the same IP
MaxConnexionPerIP=3

# Let the FTP server guess the IP to listen
DynamicIP=disabled

# Which interface is connected to internet (usefull when DynamicIP enabled)
DynamicIPInterface=eth0

# Welcome message displayed on connect
WelcomeMessage[]
WelcomeMessage[]= 
WelcomeMessage[]=    _______________________
WelcomeMessage[]= 
WelcomeMessage[]=    Welcome to eZFTP server
WelcomeMessage[]=    _______________________
WelcomeMessage[]=
WelcomeMessage[]=  

# Minimum port range for data transfert
LowPort=15000

# Maximum port range for data transfert
HighPort=16000

# List of classes that should always be seen as a folder.
# By default, if a class contains an image attribute it is seen as an image.
FolderClasses[]
FolderClasses[]=folder
FolderClasses[]=frontpage

[PutSettings]
DefaultClass=file

[FolderSettings]
FolderClassID=1
NameAttribute=name

*/ ?>