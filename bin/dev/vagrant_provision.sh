#!/bin/bash
# Script to setup the vagrant instance for running friendica
#
# DO NOT RUN on your physical machine as this won't be of any use
# and f.e. deletes your /var/www/ folder!
#
# Run as root by vagrant
#
##

ADMIN_NICK="admin"
ADMIN_PASSW="admin"

USER_NICK="user"
USER_PASSW="user"

##

echo "Friendica configuration settings"
apt-get update

#Selfsigned cert
echo ">>> Installing *.xip.io self-signed SSL"
SSL_DIR="/etc/ssl/xip.io"
DOMAIN="*.xip.io"
EXTRADOMAIN="friendica.local"
PASSPHRASE="vaprobash"
SUBJ="
C=US/
ST=Connecticut/
O=Vaprobash/
localityName=New Haven/
commonName=$DOMAIN/
subjectAltName=DNS:$EXTRADOMAIN
"
mkdir -p "$SSL_DIR"
openssl genrsa -out "$SSL_DIR/xip.io.key" 4096
openssl req -new -subj "$(echo -n "$SUBJ" | tr "\n" "/")" -key "$SSL_DIR/xip.io.key" -out "$SSL_DIR/xip.io.csr" -passin pass:$PASSPHRASE
openssl x509 -req -days 365 -in "$SSL_DIR/xip.io.csr" -signkey "$SSL_DIR/xip.io.key" -out "$SSL_DIR/xip.io.crt"


#Install apache2
echo ">>> Installing Apache2 webserver"
apt-get install -qq apache2
a2enmod rewrite actions ssl
cp /vagrant/bin/dev/vagrant_vhost.sh /usr/local/bin/vhost
chmod guo+x /usr/local/bin/vhost
vhost -s 192.168.22.10.xip.io -d /var/www -p /etc/ssl/xip.io -c xip.io -a friendica.local
a2dissite 000-default
service apache2 restart

#Install php
echo ">>> Installing PHP7"
apt-get install -qq php libapache2-mod-php php-cli php-mysql php-curl php-gd php-mbstring php-xml imagemagick php-imagick php-zip
systemctl restart apache2

#Install mysql
echo ">>> Installing Mysql"
debconf-set-selections <<< "mariadb-server mariadb-server/root_password password root"
debconf-set-selections <<< "mariadb-server mariadb-server/root_password_again password root"
apt-get install -qq mariadb-server
# enable remote access
# setting the mysql bind-address to allow connections from everywhere
sed -i "s/bind-address.*/bind-address = 0.0.0.0/" /etc/mysql/my.cnf
# adding grant privileges to mysql root user from everywhere
# thx to http://stackoverflow.com/questions/7528967/how-to-grant-mysql-privileges-in-a-bash-script for this
MYSQL=`which mysql`
Q1="GRANT ALL ON *.* TO 'root'@'%' IDENTIFIED BY 'root' WITH GRANT OPTION;"
Q2="FLUSH PRIVILEGES;"
SQL="${Q1}${Q2}"
$MYSQL -uroot -proot -e "$SQL"
# add a separate database user for friendica
$MYSQL -uroot -proot -e "CREATE USER 'friendica'@'localhost' identified by 'friendica';"
$MYSQL -uroot -proot -e "GRANT ALL PRIVILEGES ON friendica.* TO 'friendica'@'localhost';"
$MYSQL -uroot -proot -e "FLUSH PRIVILEGES"
systemctl restart mysql


#configure rudimentary mail server (local delivery only)
#add Friendica accounts for local user accounts, use email address like vagrant@friendica.local, read the email with 'mail'.
echo ">>> Installing 'Local Only' postfix"
debconf-set-selections <<< "postfix postfix/mailname string friendica.local"
debconf-set-selections <<< "postfix postfix/main_mailer_type string 'Local Only'"
apt-get install -qq postfix mailutils libmailutils-dev
echo -e "friendica1:	vagrant\nfriendica2:	vagrant\nfriendica3:	vagrant\nfriendica4:	vagrant\nfriendica5:	vagrant" >> /etc/aliases && newaliases

# Friendica needs git for fetching some dependencies
echo ">>> Installing git"
apt-get install -qq git

#make the vagrant directory the docroot
echo ">>> Symlink /var/www to /vagrant"
rm -rf /var/www/
ln -fs /vagrant /var/www

# install deps with composer
echo ">>> Installing php requirements"
apt install unzip
cd /var/www
php bin/composer.phar install


echo ">>> Setup Friendica"

# copy the .htaccess-dist file to .htaccess so that rewrite rules work
cp /vagrant/.htaccess-dist /vagrant/.htaccess

# create the friendica database
echo "create database friendica DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci" | $MYSQL -u root -proot
# import test database (disabled because too old)
#$MYSQL -uroot -proot friendica < /vagrant/friendica_test_data.sql

# install friendica
bin/console autoinstall -f /vagrant/mods/local.config.vagrant.php

# add users
# (disable a bunch of validation because this is a dev install, deh, it needs invalid emails and stupid passwords)
bin/console config system disable_email_validation 1
bin/console config system disable_password_exposed 1
bin/console user add "$ADMIN_NICK" "$ADMIN_NICK" "$ADMIN_NICK@friendica.local" en
bin/console user password "$ADMIN_NICK" "$ADMIN_PASSW"
bin/console user add "$USER_NICK" "$USER_NICK" "$USER_NICK@friendica.local" en
bin/console user password "$USER_NICK" "$USER_PASSW"

# set the admin
bin/console config config admin_email ""$ADMIN_NICK@friendica.local""


# create cronjob - activate if you have enough memory in you dev VM
# cronjob runs as www-data user
echo ">>> Installing cronjob"
echo "*/10 * * * *    www-data    cd /vagrant; /usr/bin/php bin/worker.php" >> /etc/cron.d/friendica

# friendica needs write access to /tmp
chmod 777 /tmp
