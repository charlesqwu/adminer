<?php
restart_session();
$history_all = &get_session("queries");
$history = &$history_all[DB];
if (!$error && $_POST["clear"]) {
	$history = array();
	redirect(remove_from_uri("history"));
}

page_header(lang('SQL command'), $error);

if (!$error && $_POST) {
	$fp = false;
	$query = $_POST["query"];
	if ($_POST["webfile"]) {
		$fp = @fopen((file_exists("adminer.sql") ? "adminer.sql"
			: (file_exists("adminer.sql.gz") ? "compress.zlib://adminer.sql.gz"
			: "compress.bzip2://adminer.sql.bz2"
		)), "rb");
		$query = ($fp ? fread($fp, 1e6) : false);
	} elseif ($_FILES && $_FILES["sql_file"]["error"] != 4) { // 4 - UPLOAD_ERR_NO_FILE
		$query = get_file("sql_file", true);
	}
	if (is_string($query)) { // get_file() returns error as number, fread() as false
		if (function_exists('memory_get_usage')) {
			@ini_set("memory_limit", 2 * strlen($query) + memory_get_usage() + 8e6); // @ - may be disabled, 2 - substr and trim, 8e6 - other variables
		}
		if ($query != "" && strlen($query) < 1e6 && (!$history || end($history) != $query)) { // don't add repeated and big queries
			$history[] = $query;
		}
		$space = "(\\s|/\\*.*\\*/|(#|-- )[^\n]*\n|--\n)";
		if (!ini_bool("session.use_cookies")) {
			session_write_close();
		}
		$delimiter = ";";
		$offset = 0;
		$empty = true;
		$connection2 = connect(); // connection for exploring indexes and EXPLAIN (to not replace FOUND_ROWS()) //! PDO - silent error
		if (is_object($connection2) && DB != "") {
			$connection2->select_db(DB);
		}
		$commands = 0;
		$errors = "";
		while ($query != "") {
			if (!$offset && preg_match('~^\\s*DELIMITER\\s+(.+)~i', $query, $match)) {
				$delimiter = $match[1];
				$query = substr($query, strlen($match[0]));
			} else {
				preg_match('(' . preg_quote($delimiter) . '|[\'`"]|/\\*|-- |#|$)', $query, $match, PREG_OFFSET_CAPTURE, $offset); // should always match
				$found = $match[0][0];
				$offset = $match[0][1] + strlen($found);
				if (!$found && $fp && !feof($fp)) {
					$query .= fread($fp, 1e5);
				} else {
					if (!$found && rtrim($query) == "") {
						break;
					}
					if (!$found || $found == $delimiter) { // end of a query
						$empty = false;
						$q = substr($query, 0, $match[0][1]);
						$commands++;
						echo "<pre class='jush-$jush' id='sql-$commands'>" . shorten_utf8(trim($q), 1000) . "</pre>\n";
						ob_flush();
						flush(); // can take a long time - show the running query
						$start = explode(" ", microtime()); // microtime(true) is available since PHP 5
						//! don't allow changing of character_set_results, convert encoding of displayed query
						if (!$connection->multi_query($q)) {
							echo "<p class='error'>" . lang('Error in query') . ": " . error() . "\n";
							$errors .= " <a href='#sql-$commands'>$commands</a>";
							if ($_POST["error_stops"]) {
								break;
							}
						} else {
							if (is_object($connection2) && preg_match("~^$space*(USE)\\b~isU", $q)) {
								$connection2->query($q);
							}
							do {
								$result = $connection->store_result();
								$end = explode(" ", microtime());
								$time = " <span class='time'>(" . lang('%.3f s', max(0, $end[0] - $start[0] + $end[1] - $start[1])) . ")</span>";
								if (is_object($result)) {
									select($result, $connection2);
									echo "<p>" . ($result->num_rows ? lang('%d row(s)', $result->num_rows) : "") . $time;
									if ($connection2 && preg_match("~^($space|\\()*SELECT\\b~isU", $q)) {
										$id = "explain-$commands";
										echo ", <a href='#$id' onclick=\"return !toggle('$id');\">EXPLAIN</a>\n";
										echo "<div id='$id' class='hidden'>\n";
										select(explain($connection2, $q));
										echo "</div>\n";
									}
								} else {
									if (preg_match("~^$space*(CREATE|DROP|ALTER)$space+(DATABASE|SCHEMA)\\b~isU", $q)) {
										restart_session();
										set_session("dbs", null); // clear cache
										session_write_close();
									}
									echo "<p class='message' title='" . h($connection->info) . "'>" . lang('Query executed OK, %d row(s) affected.', $connection->affected_rows) . "$time\n";
								}
								$start = $end;
							} while ($connection->next_result());
						}
						$query = substr($query, $offset);
						$offset = 0;
					} else { // find matching quote or comment end
						while (preg_match('~' . ($found == '/*' ? '\\*/' : (ereg('-- |#', $found) ? "\n" : "$found|\\\\.")) . '|$~s', $query, $match, PREG_OFFSET_CAPTURE, $offset)) { //! respect sql_mode NO_BACKSLASH_ESCAPES
							$s = $match[0][0];
							$offset = $match[0][1] + strlen($s);
							if (!$s && $fp && !feof($fp)) {
								$query .= fread($fp, 1e6);
							} elseif ($s[0] != "\\") {
								break;
							}
						}
					}
				}
			}
		}
		if ($errors && $commands > 1) {
			echo "<p class='error'>" . lang('Error in query') . ": $errors\n";
		}
		if ($empty) {
			echo "<p class='message'>" . lang('No commands to execute.') . "\n";
		}
		//! MS SQL - SET SHOWPLAN_ALL OFF
	} else {
		echo "<p class='error'>" . upload_error($query) . "\n";
	}
}
?>

<form action="" method="post" enctype="multipart/form-data">
<p><?php
$q = $_GET["sql"]; // overwrite $q from if ($_POST) to save memory
if ($_POST) {
	$q = $_POST["query"];
} elseif ($_GET["history"] != "") {
	$q = $history[$_GET["history"]];
}
textarea("query", $q, 20);
echo ($_POST ? "" : "<script type='text/javascript'>document.getElementsByTagName('textarea')[0].focus();</script>\n");
echo "<p>" . (ini_bool("file_uploads") ? lang('File upload') . ': <input type="file" name="sql_file">' : lang('File uploads are disabled.'));

?>
<p>
<input type="hidden" name="token" value="<?php echo $token; ?>">
<input type="submit" value="<?php echo lang('Execute'); ?>" title="Ctrl+Enter">
<?php
echo checkbox("error_stops", 1, $_POST["error_stops"], lang('Stop on error'));

print_fieldset("webfile", lang('From server'), $_POST["webfile"]);
$compress = array();
foreach (array("gz" => "zlib", "bz2" => "bz2") as $key => $val) {
	if (extension_loaded($val)) {
		$compress[] = ".$key";
	}
}
echo lang('Webserver file %s', "<code>adminer.sql" . ($compress ? "[" . implode("|", $compress) . "]" : "") . "</code>");
echo ' <input type="submit" name="webfile" value="' . lang('Run file') . '">';
echo "</div></fieldset>\n";

if ($history) {
	print_fieldset("history", lang('History'), $_GET["history"] != "");
	foreach ($history as $key => $val) {
		//! save and display timestamp
		echo '<a href="' . h(ME . "sql=&history=$key") . '">' . lang('Edit') . "</a> <code class='jush-$jush'>" . shorten_utf8(ltrim(str_replace("\n", " ", str_replace("\r", "", preg_replace('~^(#|-- ).*~m', '', $val)))), 80, "</code>") . "<br>\n";
	}
	echo "<input type='submit' name='clear' value='" . lang('Clear') . "'>\n";
	echo "</div></fieldset>\n";
}
?>

</form>
