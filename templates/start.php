<<?php ?>?xml version="1.0" encoding="UTF-8"?>
<!--
$Id: start.php,v 3.1 2012/06/04 18:15:37 confetto Exp $
$Name: release-3-0 $
-->
<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Strict//EN"
	"http://www.w3.org/TR/xhtml1/DTD/xhtml1-strict.dtd">
<html xmlns="http://www.w3.org/1999/xhtml" xml:lang="ja" lang="ja">

<head>
	<meta name="viewport" content="width=device-width" />
	<link rel="stylesheet" type="text/css" href="chat.css" />
	<link rel="contents" href="https://web.wakayama-u.ac.jp/~yoshino/lab/" title="吉野研のWebに戻る" />
	<link rev="made" href="http://confetto.s31.xrea.com/" />
	<title>映画おすすめチャットルーム</title>
</head>

<body>

<h1>映画おすすめチャットルーム</h1>

<div id="form">
	<h2>入室フォーム</h2>
	<form action="<?php o($_SERVER['PHP_SELF'])?>" method="post">
		<div>
			<label>名前<input name="name" value="<?php o($name)?>" /></label>
			<label>文字色
				<select name="color">
					<?php foreach ($GLOBALS['COLORS'] as $name => $value): ?>
						<?php if ($value === $color): ?>
							<option value="<?php o($value)?>" style="color:<?php o($value)?>;" selected="selected">■<?php o($name)?></option>
						<?php else: ?>
							<option value="<?php o($value)?>" style="color:<?php o($value)?>;">■<?php o($name)?></option>
						<?php endif; ?>
					<?php endforeach; ?>
				</select>
			</label>
			<input type="submit" value="入室" />
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

<div id="navigation">
	<h2>ページ移動</h2>
	<ul>
		<li><a href="https://web.wakayama-u.ac.jp/~yoshino/lab/" rel="contents">吉野研のWebに戻る</a></li>
	</ul>
</div>

<address>
	<a href="http://confetto.s31.xrea.com/" rev="made">chat/plain</a>
	<?php o($version)?>
</address>

<script type="text/javascript" src="chat.js"></script>

</body>
</html>
