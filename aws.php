<?php
$remote_hostname = gethostbyaddr($_SERVER['REMOTE_ADDR']);
$mailgun_api_key = trim(file_get_contents("mailgunapikey.cfg"));

if (!(preg_match('/^curl/', $_SERVER['HTTP_USER_AGENT']) && preg_match('/amazonaws.com$/', $remote_hostname))){
    // could fail if custom rdns exists -- but no fresh instance is going to have that.
	print "# sorry, this has to be run from your AWS instance<br>\n";
	exit;
}

// we require a host name & email

// allow alphanum, dash and underscore in hostnames only
$hostname = preg_replace('/[^\da-z\-_]/', '', strtolower($_REQUEST['hostname']));
$email = $_REQUEST['email'];

if (!($hostname && preg_match('/.@./', $email))){
	print "sorry, please pass hostname & email<br>";
	exit;
}

list($username, $emaildomain) = explode('@', $email);

// if hostname is already defined, increment
$dns_check = gethostbyname("$hostname.rshiny.space.");

if ($dns_check != "$hostname.rshiny.space."){
 $i = 1;
 while ("{$hostname}-{$i}.rshiny.space." != gethostbyname("{$hostname}-{$i}.rshiny.space.")){
     $i++;
 }
 $hostname = "$hostname-$i";
}
?>
## rshiny aws bash bootstrapper
## e.g.  'curl -s 'https://rshiny.space/aws.php?hostname=yourname&email=youremail' | bash'

# output this script to logfile
exec > >(tee -i /tmp/rshinyspace-installer.log)
exec 2>&1

do_install() {

echo "Starting rshiny.space server install..."
date

# assumes ubuntu 16.04 LTS (Xenial Xerus)
OS=$(cat /etc/issue)
if [[ "$OS" =~ ^Ubuntu ]]; then
 echo "OS check Ok";                                
else
 echo "failure, wrong OS selected"
 exit
fi

# we are already root, no need for sudo-ing

# we need some swap or compiling stuff is going to blow up
touch /var/swapfile
chmod 600 /var/swapfile
dd if=/dev/zero of=/var/swapfile bs=2M count=1024
mkswap /var/swapfile
swapon /var/swapfile
sh -c 'echo "/var/swapfile swap swap defaults 0 0 " >> /etc/fstab'
export DEBIAN_FRONTEND=noninteractive

apt-get -y dist-upgrade # upgrade to 16.04.2
apt-get -y upgrade # upgrade any packages (shouldn't do anything)

#repos we're going to need
add-apt-repository -y ppa:certbot/certbot
apt-add-repository -y 'deb https://cloud.r-project.org/bin/linux/ubuntu  xenial/'
apt-add-repository -y ppa:marutter/c2d4u
apt-get -y update

#deb https://archive.ubuntu.com/ubuntu/ trusty-backports main restricted universe #

apt-key adv --keyserver keyserver.ubuntu.com --recv-keys E084DAB9 # fetch key for CRAN repo
apt-get -y update # get available packages from new repo(s)

apt-get -y install r-base \
         r-base-dev \
         libssl-dev \
         libcurl4-openssl-dev \
         libxml2-dev \
         emacs \
         nginx \
         python-certbot-nginx \
         pwgen \
         apache2-utils

apt-get -y upgrade # upgrade any packages

# instead of install.packages in R (and compiling per package), we're doing it via cran2deb https://launchpad.net/~marutter/+archive/ubuntu/c2d4u
# based on the dartistics.com R packages list from Tim
apt-get -y install r-cran-devtools r-cran-dygraphs r-cran-forecast r-cran-googleauthr r-cran-googleanalyticsr  r-cran-miniui r-cran-plotly r-cran-reshape2 r-cran-rmarkdown r-cran-rsitecatalyst r-cran-rpart r-cran-rpart.plot r-cran-scales r-cran-searchconsoler r-cran-shiny r-cran-shinyjs r-cran-tidyverse r-cran-venneuler r-cran-xlsx r-cran-xts r-cran-zoo

R -e 'install.packages(c("shinyFiles"), repos="http://cran.rstudio.com/")'

apt-get install gdebi-core  # gdebi package used to install Shiny Server
wget -q https://download3.rstudio.org/ubuntu-12.04/x86_64/shiny-server-1.5.3.838-amd64.deb # download Shiny server
gdebi -n shiny-server-1.5.3.838-amd64.deb
/opt/shiny-server/bin/deploy-example default
# systemctl enable shiny-server # shouldn't be necessary

# install rstudio-server
wget -q https://download2.rstudio.org/rstudio-server-1.0.143-amd64.deb
gdebi -n rstudio-server-1.0.143-amd64.deb

# setup nginx proxy server
cd /etc/nginx/sites-available/
curl https://rshiny.space/nginx-rshiny > rshiny
perl -i -p -e 's/%%hostname%%/<?php echo $hostname; ?>/g' rshiny
cd ../sites-enabled/
rm default
ln -s ../sites-available/rshiny .
mkdir /var/www/<?php echo $hostname; ?>.rshiny.space
echo 'Hey, it worked!<br><br><a href="/shiny">Shiny</a><br><a href="/rstudio">Rstudio</a><br>' > /var/www/<?php echo $hostname; ?>.rshiny.space/index.html
systemctl enable nginx
systemctl start nginx

# setup DNS
# create a local bash script that calls our DNS helper
cat << 'EOF' > /usr/local/bin/rshiny-dns.sh
#!/bin/bash
HOSTNAME=$1
EC2NAME=$(curl -s http://169.254.169.254/latest/meta-data/public-hostname) # get the ec2 public DNS name
curl -s -d "hostname=$HOSTNAME&ec2name=$EC2NAME" "https://rshiny.space/adddns.php" # create a CNAME on the rshiny.space domain for it
EOF
chmod 755 /usr/local/bin/rshiny-dns.sh
/usr/local/bin/rshiny-dns.sh <?php echo $hostname . "\n"; ?>
# set CNAME to update at reboot by running script from rc.local (since unless we have a static IP it'll change every reboot)
perl -i -p -e 's~exit 0~/usr/local/bin/rshiny-dns.sh <?php echo $hostname;?>\nexit 0~' /etc/rc.local

HTPASSWD=$(pwgen -BN 1 10)
UNIXPASSWD=$(pwgen -BN 1 10)
htpasswd -bc /etc/nginx/.htpasswd <?php echo $email; ?> $HTPASSWD
addgroup rusers
adduser --disabled-password --gecos "" <?php echo "$username\n";?>
adduser <?php echo $username;?> rusers
usermod -aG sudo <?php echo $username . "\n";?>
echo <?php echo $username;?>:$UNIXPASSWD | chpasswd
service nginx restart
hostnamectl set-hostname '<?php echo $hostname;?>.rshiny.space'

cd /home/<?php echo $username . "\n"; ?>
mkdir shiny-server ; chown <?php echo $username; ?>:<?php echo $username; ?> shiny-server
mkdir .ssh ; chown <?php echo $username; ?>:<?php echo $username; ?> .ssh
# allow user to login with the ubuntu user public key
cp /home/ubuntu/.ssh/authorized_keys .ssh/
chown <?php echo $username; ?>:<?php echo $username; ?> .ssh/authorized_keys
# allow shiny apps to be accessed from under user's homedir
cd /srv/shiny-server ; ln -s /home/<?php echo $username;?>/shiny-server <?php echo $username . "\n";?>


R -e 'devtools::install_github("bnosac/cronR")'
# allow cronR to write to /usr/local/lib/R/site-library/cronR/extdata/RscriptRepository.rds
chmod g+w /usr/local/lib/R/site-library/cronR/extdata/
chgrp rusers /usr/local/lib/R/site-library/cronR/extdata/

## https
#certbot -n --nginx --domains <?php echo $hostname;?>.rshiny.space --agree-tos --email <?php echo "$email\n"; ?>

# connect to dropbox? would connect as ~/Dropbox folder for user and require retrieving the 
#wget -O - "https://www.dropbox.com/download?plat=lnx.x86_64" | tar xzf -
#curl 'https://www.dropbox.com/download?dl=packages/dropbox.py' > dropbox.py

date

# send email to the user.

curl -s --user 'api:key-<?php echo $mailgun_api_key; ?>' \
    https://api.mailgun.net/v3/mg.rshiny.space/messages \
    -F from='Rshiny Space <noreply@mg.rshiny.space>' \
    -F to='<?php echo $email; ?>' \
    -F subject='Your rshiny server setup is done' \
    -F html="Good news, your setup of http://<?echo "$hostname.rshiny.space";?> is done.<br>Your web login is u: <?php echo $email; ?> p: $HTPASSWD<br>Your rstudio and user login is u: <?php echo $username;?> p: $UNIXPASSWD<br><br>The full installation log is attached." \
    -F attachment=@/tmp/rshinyspace-installer.log
}
do_install
