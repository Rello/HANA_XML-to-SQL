<?php

if (isset($_POST['output'])) {$xml_output=$_POST['output'];}
if (isset($_POST['xml'])) {
    $xml_input=$_POST['xml'];
    include ($_SERVER['DOCUMENT_ROOT']."/xmltosql/xmltosql.php");
}

?>


<html>
<head>
</head>
<body>
<h1>SAP HANA XML to SQL Converter</h1>
<h2>convert a grafical Calulation View to plain SQL</h2>
<br><br><br>

<form action="index.php" method="post">
<textarea name="xml" rows="20" cols="100"><?php echo $xml_input;?></textarea><br>
<input name="output" value="<?php echo $xml_output;?>"><br>
<button type="submit">go</button>
</form>
<textarea name="result" rows="20" cols="100"><?php echo $result;?></textarea>
</body>
</html>