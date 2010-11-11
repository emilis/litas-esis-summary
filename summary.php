<!DOCTYPE html>
<html><head>
<meta http-equiv="Content-Type" content="text/html; charset=utf-8">
<style type="text/css">
/* * { font-family: Arial, sans-serif; } */
table { margin: 2em; }
.LTL { background-color: #cfc; }
.EUR { background-color: #ccf; }
.typeB { font-style: italic; }
</style>
</head><body>
<?php

//error_reporting(E_ALL & ~E_NOTICE);

$dir = (count($argv) > 1) ? $argv[1] : dirname(__FILE__);

// create files array:
$files = array();
foreach (scandir($dir) as $line) {
    if (substr($line, -4) == ".acc") {
        array_push($files, "$dir/$line");
    }
}
sort($files);

$alltrans = array();
$all = array();
echo '<table border="1" cellspacing="0" cellpadding="2">';
echo '<thead><tr><th>File</th><th>Account</th><th>Currency</th><th>from</th><th>to</th><th>checksum</th></tr></thead><tbody>';
foreach ($files as $file_name) {
    $data = process_file($file_name);
    print_file($file_name, $data);

    $currency = $data["header"]["Currency"];

    foreach ($data["transactions"] as $trans) {
        $key = "$trans[Date] $trans[Time] $trans[TransactionID]";
        $trans["Currency"] = $currency;

        $alltrans[$key] = $trans;
        $all[$key] = $trans;
    }
    foreach ($data["balance"] as $bal) {
        $bal["Currency"] = $currency;
        if ($bal["Transaction"] == "LikutisPR") {
            $key = "$bal[Date] 00:00:00 $currency";
            $all[$key] = $bal;
        } else if ($bal["Transaction"] == "LikutisPB") {
            $key = "$bal[Date] 23:59:59 $currency";
            $all[$key] = $bal;
        }
    }
}
echo '</tbody></table>';
ksort($alltrans);
ksort($all);

print_all($all);

echo "</body></html>";


/*
 * FUNCTIONS
 */



/**
 * Split data file line (separator == "\t").
 */
function split_line($line) {
    return explode("\t", str_replace("\r", "", $line));
}


/**
 *
 */
function print_file($name, $data) {
    $name = basename($name);
    $header = $data["header"];
    
    echo "<tr class=\"$header[Currency]\">";
    echo "<td>$name</td>";
    echo "<td>$header[AccountNumber]</td>";
    echo "<td>$header[Currency]</td>";

    foreach ($data["balance"] as $bal) {
        if ($bal["Transaction"] == "LikutisPR") {
            echo "<td>$bal[Date]</td>";
        }
    }
    foreach ($data["balance"] as $bal) {
        if ($bal["Transaction"] == "LikutisPB") {
            echo "<td>$bal[Date]</td>";
        }
    }

    echo "<td>" . $data["footer"]["checksum-ok"] . "</td>";
    echo "</tr>";
}


/**
 *
 */
function print_data($data) {

    /*
    var_dump($data);
    echo "\n\n\n";
    return false;
     */

    $header = $data["header"];

    echo "\n<hr>\n";
    echo "<table border=\"1\">\n";
    echo "<caption>$header[FileName] @ $header[Date] $header[Time]<br>$header[AccountNumber] $header[Currency]</caption>\n";
    echo "<thead><tr>";
    echo '<th>Data</th>';
    echo '<th>Suma</th>';
    echo '<th>Paskirtis,detalės</th>';
    echo '<th>Asmuo/organizacija</th>';
    echo "</tr></thead>\n";
    echo "<tbody>\n";

    print_transactions($data["transactions"], $header["Currency"]);

    echo "</tbody>\n";
    echo "<tfoot>\n";
    foreach ($data["balance"] as $bal) {
        echo "<tr>";
        echo "<td>$bal[Date]</td>";
        echo "<td>" . ($bal["Amount"]/100) . " $header[Currency]</td>";
        echo "<td colspan=\"2\">$bal[Transaction]</td>";
        echo "</tr>\n";
    }
    echo "<tr><th colspan=\"4\">Checksum OK?: " . sprintf("%b (%f)", $data["footer"]["checksum-ok"], $data["footer"]["ControllingAmount"]) . "</th></tr>\n";
    echo "</tfoot></table>";

    echo "<hr>\n";
}


/**
 *
 */
function print_transactions($transactions, $currency = "???") {
    foreach ($transactions as $trans) {
        echo "<tr>";
        echo "<td>$trans[Date]</td>";
        echo "<td>";
            echo ($trans["CD"] == "C") ? "+" : "-";
            echo $trans["Amount"]/100;
            echo (array_key_exists("Currency", $trans)) ? $trans["Currency"] : $currency;
            echo "</td>";
        echo "<td>$trans[Details]</td>";
        echo "<td>" . $trans["Counterparty-Account"] . "<br>" . $trans["Counterparty-Designation"] . "<br>" . $trans["Counterparty-RegNo"] . "</td>";
        echo "</tr>\n";
    }

}

/**
 *
 */
function print_all($transactions) {
    echo '<table border="1" cellspacing="0" cellpadding="5">';
    echo "<thead><tr><th>Date</th><th nowrap=\"nowrap\">Amount</th><th>Details</th><th>Counterparty</th></tr></thead>\n";
    echo "<tbody>\n";

    foreach ($transactions as $trans) {
        $cd = array_key_exists("CD", $trans) ? $trans["CD"] : "B";

        echo "<tr class=\"$trans[Currency] type$cd\">";
        echo "<td>$trans[Date]</td>";
        
        if ($cd == "C" || $cd == "D") {
            echo '<td nowrap="nowrap" align="right">';
                echo ($cd == "C") ? "+" : "-";
                echo sprintf("%0.2f ", $trans["Amount"]/100);
                echo $trans["Currency"];
                echo "</td>";
            echo "<td>$trans[Details]</td>";
                echo "<td>" . $trans["Counterparty-Account"] . "<br>" . $trans["Counterparty-Designation"] . "<br>" . $trans["Counterparty-RegNo"] . "</td>";
        } else {
            echo '<td nowrap="nowrap" align="right">' . sprintf("=%0.2f %s", $trans["Amount"]/100, $trans["Currency"]) . "</td>";
            echo "<td colspan=\"2\">Likutis</td>";
        }
        
        echo "</tr>\n";
    }

    echo "</tbody></table>\n";
}

/**
 *
 */
function process_file($file_name) {
    $contents = trim(file_get_contents($file_name));
    $contents = iconv("windows-1257", "utf-8", $contents);
    $contents = explode("\n", $contents);
    $contents = array_map("split_line", $contents);

    $header = array("FileName" => basename($file_name));
    $footer = array();
    $balance = array();
    $transactions = array();

    foreach ($contents as $line) {
        switch ($line[0]) {
            case "000":
                $header = parse_header($line, $header);
                break;
            case "999":
                $footer = parse_footer($line, $footer);
                break;
            case "020":
                $balance = parse_balance($line, $balance);
                break;
            case "010":
                $transactions = parse_transaction($line, $transactions);
                break;
            default:
                die("Unsupported line: " . implode("; ", $line));
        }
    }

    $footer["checksum-ok"] = check_checksum($footer, $transactions, $balance);

    return array(
        "header" => $header,
        "footer" => $footer,
        "balance" => $balance,
        "transactions" => $transactions
    );
}


/**
 *
 */
function check_checksum($footer, $transactions, $balance) {
    $checkt = $transactions; //array_slice($transactions, -9);
    $checksum = 0;
    foreach ($checkt as $trans) {
        $checksum += $trans["Amount"];
    }
    foreach ($balance as $bal) {
        $checksum += $bal["Amount"];
    }

    $checksum = floatval($checksum);
    $footer["ControllingAmount"] = floatval($footer["ControllingAmount"]);

    return ($checksum == $footer["ControllingAmount"]);
}


/**
 *
 */
function parse_header($line, $header) {
    if ($line[0] != "000") {
        die("Invalid header line: " . implode("; ", $line));
    }

    $fields = array("LineID", "Date", "Time",
        "BIC", "Bank", "Bank-RegNo", "Bank-Address", "Bank-City", "Bank-Other",
        "CIF", "Customer", "Customer-RegNo", "Customer-Address", "Customer-City", "Customer-Other",
        "Branch", "AccountNumber", "Currency");

    foreach ($fields as $i => $name) {
        $header[$name] = $line[$i];
    }

    $header["Date"] = date("Y-m-d", strtotime($header["Date"]));
    $t = $header["Time"];
    $header["Time"] = "$t[0]$t[1]:$t[2]$t[3]:$t[4]$t[5]";

    return $header;
}


/**
 *
 */
function parse_footer($line, $footer) {
    if ($line[0] != "999") {
        die("Invalid footer line: " . implode("; ", $line));
    }

    $footer["LineID"] = $line[0];
    $footer["ControllingAmount"] = $line[1];

    return $footer;
}


/**
 *
 */
function parse_balance($line, $balance) {
    $bal = array();
    $bal["Transaction"] = $line[1];
    $bal["Date"] = date("Y-m-d", strtotime($line[2]));
    $bal["Amount"] = @$line[4];
    $bal["Equivalent"] = @$line[5];

    array_push($balance, $bal);

    return $balance;
}


/**
 *
 */
function parse_transaction($line, $transactions) {
    $trans = array();

    $fields = array("LineID", "Transaction", "Date", "Time", "Amount", "Equivalent", "CD", "OrigAmount", "OrigCurrency",
        "DocumentNumber", "TransactionID", "CustomersCode", "PaymentCode", "Details",
        "BIC", "Counterparty-Bank", "Counterparty-Account", "Counterparty-Designation", "Counterparty-RegNo"
    );

    foreach ($fields as $i => $name) {
        $trans[$name] = $line[$i];
    }

    $trans["Date"] = date("Y-m-d", strtotime($trans["Date"]));
    $t = $trans["Time"];
    $trans["Time"] = "$t[0]$t[1]:$t[2]$t[3]:$t[4]$t[5]";
    $trans["Amount"] = $trans["Amount"];
    $trans["Equivalent"] = $trans["Equivalent"];
    $trans["OrigAmount"] = $trans["OrigAmount"];

    array_push($transactions, $trans);

    return $transactions;
}
