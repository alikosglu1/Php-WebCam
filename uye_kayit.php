<?php
if(!$_POST){
   die('Hatalı istek');
}
$db = @new mysqli("localhost", "root", "1234", "yeni");
if ($db->connect_errno)
    die("Bağlantı hatası:" . $db->connect_error);
$db->set_charset("utf8");
//dışarıdan gelen bilgileri zararsız hale getirelim
$_POST = array_map(array( $db, 'real_escape_string'), $_POST);
$resim = "./uye/" . $_POST['resim'];
if (file_exists($resim)) {
    $sql = "INSERT INTO uyeler(ad, soyad,mail,sifre,resim) VALUES('{$_POST['ad']}', '{$_POST['soyad']}', '{$_POST['mail']}', MD5({$_POST['sifre']}), '{$_POST['resim']}')";
    $db->query($sql) or die($db->error);
    echo "bilgiler eklendi<br/>";
} else {
    echo "Hata: resim bulunmadı<br/>";
}
$db->close();
?>