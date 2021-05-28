<?php
$file = date('YmdHis') . '.jpg';
$result = @file_put_contents("./uye/".$file, file_get_contents('php://input') );
if (!$result) {
  die("Hata: dosya kaydedilmedi,dosya yolunu veya izinleri kontrol edin\n");
}
$url = 'http://' . $_SERVER['HTTP_HOST'] . dirname($_SERVER['REQUEST_URI']) . '/uye/' . $file;

include "FaceDetector.php";
$detector = new svay\FaceDetector('detection.dat'); 
$detector->faceDetect("./uye/".$file);
if($detector->toJpeg("./uye/".$file)){
$form =<<<HTML
<img src="$url" width="200"><br />
<form method="post" action="uye_kayit.php">
<input type="hidden" name="resim" value="$file" />
<input type="text" name="ad" /> Ad <br />
<input type="text" name="soyad" /> Soyad <br />
<input type="mail" name="mail" /> Mail <br />
<input type="password" name="sifre" /> Şifre <br />
<input type="submit" value="Kayıt Ol" />
</form>
HTML;
}else{
	$form ="<h3>Profil Resmi Geçerli Değil</h3>";
	unlink("./uye/".$file);
}
print $form;
?>