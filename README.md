# ISUCON2

## ユーザをapacheグループに追加 

    ```
    sudo /usr/sbin/usermod -G apache isu-user
    ```

## リポジトリ作成

    ```
    sudo mkdir /var/www/svn
    sudo svnadmin create /var/www/svn/isucon2
    sudo chown -R apache:apache /var/www/svn/isucon2
    sudo chmod -R 775 /var/www/svn/isucon2
    svn co file:///var/www/svn/isucon2
    cd isucon2
    mkdir trunk tags branches
    svn add trunk tags branches
    svn commit -m 'first commit'
    ```

## apacheのログディレクトリ作成

    ```
    sudo mkdir /var/log/httpd/webapp
    sudo mkdir /var/log/httpd/proxy
    sudo chown -R apache:apache /var/log/httpd/webapp
    sudo chown -R apache:apache /var/log/httpd/proxy
    ```

## ドキュメントルートにアプリを配置

    ```
    cd /var/www/html
    sudo svn co file:///var/www/svn/isucon2/trunk/webapp
    sudo chown -R apache:apache webapp
    sudo chmod -R 775 webapp
    ```

## リポジトリチェックアウト

    ```
    cd
    svn co file:///var/www/svn/isucon2/trunk
    ```

## シンボリックリンクに変更

    ```
    cd /etc/httpd
    sudo mv conf conf.org
    sudo mv conf.d conf.d.org
    sudo ln -s /home/isu-user/trunk/etc/httpd/conf conf
    sudo ln -s /home/isu-user/trunk/etc/httpd/conf.d conf.d
    ```

## memcachedインストール

    ```
    sudo yum install memcached 
    sudo yum install php-pecl-memcached
    ```

## apache起動

    ```
    sudo /etc/init.d/httpd start
    ```

## ブラウザでサイトにアクセス

    http://ec2-54-238-155-221.ap-northeast-1.compute.amazonaws.com:5000/
