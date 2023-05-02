<<?php ?>?xml version="1.0" encoding="UTF-8"?>
<!--
$Id: speak.php,v 3.1 2012/06/04 18:15:37 confetto Exp $
$Name: release-3-0 $
-->
<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Strict//EN"
	"http://www.w3.org/TR/xhtml1/DTD/xhtml1-strict.dtd">
<html xmlns="http://www.w3.org/1999/xhtml" xml:lang="ja" lang="ja">

<head>
	<meta name="viewport" content="width=device-width" />
	<link rel="stylesheet" type="text/css" href="chat.css" />
	<title>映画おすすめチャットルーム</title>
</head>

<body>

<h1>映画おすすめチャットルーム</h1>

<div id="form">
	<h2>発言フォーム</h2>
	<form action="<?php o($_SERVER['PHP_SELF'])?>" method="post">
		<div>
			<input name="mssg" value="" size="70" />
			<input type="hidden" name="sid" value="<?php o($sessionID)?>" />
			<input type="submit" value="発言/更新" />
			<input type="submit" name="quit" value="退室" />
		</div>
	</form>
</div>

<div id="messages">
	<h2>発言一覧</h2>
	<dl id="message-list">
		<?php foreach ($messages as $message): ?>
			<?php if ($message->isInfo): ?>
				<dt class="info"><?php o($message->name)?></dt>
			<?php else: ?>
				<dt style="color:<?php o($message->color)?>"><?php o($message->name)?></dt>
			<?php endif; ?>
			<dd style="color:<?php o($message->color)?>">
				<?php o($message->message)?>
				<span class="message-date">(<?php o($message->date)?>)</span>
			</dd>
		<?php endforeach; ?>
	</dl>
</div>

<div id="sessions">
	<h2>参加者 (<span id="session-count"><?php o(count($sessions))?></span>)</h2>
	<?php if (0 < count($sessions)): ?>
		<ul id="session-list">
			<?php foreach ($sessions as $session): ?>
				<li style="color:<?php o($session->color);?>"><?php o($session->name);?></li>
			<?php endforeach; ?>
		</ul>
	<?php else: ?>
		<p>なし</p>
	<?php endif; ?>
</div>

<script type="text/javascript" src="chat.js"></script>

</body>
</html>
