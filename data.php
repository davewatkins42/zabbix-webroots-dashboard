<?php
  $service  = filter_input(INPUT_GET, 'service', FILTER_SANITIZE_STRING);
  $port     = filter_input(INPUT_GET, 'port',    FILTER_SANITIZE_NUMBER_INT);
  $host_ids = filter_input(INPUT_GET, 'hosts',   FILTER_SANITIZE_NUMBER_FLOAT, array('flags' => FILTER_FLAG_ALLOW_THOUSAND));
  $host_ids = explode(',', $host_ids);

  # Ensure values passed validation
  if($service == "" || $port == "" || !is_array($host_ids)) {
    print "<strong>Invalid or missing request data.</strong>";
    exit;
  }

  require 'config.php';

  require 'PhpZabbixApi_Library/ZabbixApiAbstract.class.php';
  require 'PhpZabbixApi_Library/ZabbixApi.class.php';

  # Connect to Zabbix
  try {
    $api = new ZabbixApi($api_dest, $api_user, $api_pass);
  } catch(Exception $e) {
    echo $e->getMessage();
    exit;
  }

  # Fetch hostnames and FQDNs from host IDs
  $hosts = $api->hostGet(array(
    'hostids'  => $host_ids,
    'output'   => array('hostid', 'name', 'host'),
    ));

  # Place hosts into environments
  $envs = array();
  foreach($hosts as $host) {
    $envs[substr($host->name,-6,3)][$host->hostid] = array('hostid' => $host->hostid, 'name' => $host->name, 'host' => $host->host);
  }

  # Fetch standard items
  $items = $api->itemGet(array(
    'hostids'     => $host_ids,
    'filter'      => array('key_' => array('system.cpu.load[,avg1]', 'vm.memory.size[pused]')),
    'output'      => array('hostid', 'itemid', 'key_', 'name'),
    ));

  # Fetch discovery items
  $discovery_items = $api->itemGet(array(
    'hostids'     => $host_ids,
    'search'      => array('key_' => 'vfs.fs.size[*,pfree]'),
    'searchWildcardsEnabled' => true,
    'output'      => array('hostid', 'itemid', 'key_', 'name'),
    ));

  # Merge items into a single array
  $items = array_merge($items, $discovery_items);

  # Reconstruct the array so the history query is more efficient
  foreach($items as $item) {
    $item_hist[$item->itemid] = array(
      'hostid' => $item->hostid,
      'key'    => $item->key_,
      'data'   => array(),
      );
  }

  # Query history, twice to pick up both int and float values
  $history = $api->historyGet(array(
    'itemids'   => array_keys($item_hist),
    'history'   => 0,
    'sortfield' => 'clock',
    'sortorder' => 'DESC',
    'time_from' => time()-659, # When set to 600 we sometimes missed the 10th value
    'time_till' => time(),
    'output'    => 'extend',
    ));

  foreach($history as $hist_val) {
    $item_hist[$hist_val->itemid]['data'][] = $hist_val->value;
  }

  $history = $api->historyGet(array(
    'itemids'   => array_keys($item_hist),
    'history'   => 3,
    'sortfield' => 'clock',
    'sortorder' => 'DESC',
    'time_from' => time()-600,
    'time_till' => time(),
    'output'    => 'extend',
    ));

  foreach($history as $hist_val) {
    $item_hist[$hist_val->itemid]['data'][] = $hist_val->value;
  }
?>
<table class="table">
  <tr>
    <th>Environment</th>
    <th>Node</th>
    <th>Load</th>
    <th>RAM</th>
    <th>Disk</th>
    <th width=80%>Links</th>
  </tr>
<?php
  $prev_env = "";
  foreach($envs as $env => $hosts) {
    switch($env) {
      case "prd": $label = "danger";  $env_name = "production";  break;
      case "stg": $label = "warning"; $env_name = "staging";     break;
      case "tst": $label = "info";    $env_name = "testing";     break;
      case "dev": $label = "success"; $env_name = "development"; break;
      default: break;
    }

    foreach($hosts as $host) {
      print " <tr>\n";
      if($prev_env != $env) {
        print "   <td rowspan=\"" . count($hosts) . "\"><span class=\"label label-${label}\">${env_name}</span></td>\n";
        $prev_env = $env;
      }
      print "   <td>" . $host['name'] . "</td>\n";

      # Find all item history for the given hostid.  This may be over-complicating
      # things, but at the moment I can't think of a better way to do this.
      $host_data = array();
      array_filter($item_hist, function($value, $key) {
        global $host, $host_data;
        if($host['hostid'] == $value['hostid']) {
          $host_data[$value['key']] = $value['data'];
        }
      });

      print '   <td><span class="w-tooltip" data-toggle="tooltip" title="" data-original-title="current: ' . $host_data['system.cpu.load[,avg1]'][9] . '" href="#">' . "\n";
      print '     <span class="line" data-width="40" data-min="0" data-max="4">' . implode(',', $host_data['system.cpu.load[,avg1]']) . '</span>' . "\n    </td>\n";
      print '   <td><span class="w-tooltip" data-toggle="tooltip" title="" data-original-title="current: ' . $host_data['vm.memory.size[pused]'][9] . '%" href="#">' . "\n";
      print '     <span class="bar" data-width="40" data-min="0" data-max="100">' . implode(',', $host_data['vm.memory.size[pused]']) . '</span>' . "\n   </td>\n";
      print '   <td nowrap="nowrap">' . "\n";
      # Find all array keys that are filesystems
      foreach(array_intersect_key($host_data, array_flip(preg_grep('/^vfs.fs.size/', array_keys($host_data)))) as $fs => $data) {
        preg_match('/^vfs.fs.size\[(.*),pfree\]/', $fs, $matches);
        $fs_name = $matches[1];
        $fs_used = (100 - $host_data[$fs][9]);
        print "     <span class=\"w-tooltip\" data-toggle=\"tooltip\" title=\"\" data-original-title=\"${fs_name} (${fs_used}%)\" href=\"#\">\n";
        print "       <span class=\"pie\">${fs_used}/100</span>\n";
        print "     </span>\n";
      }
      print "   </td>\n";
?>
    <td>
      <button type="button" class="btn btn-default btn-xs">
          <span class="glyphicon glyphicon-plus-sign"></span> Health Check
      </button>
      &nbsp;
      <button type="button" class="btn btn-default btn-xs">
          <span class="glyphicon glyphicon-dashboard"></span> Zabbix
      </button>
      &nbsp;
      <div class="btn-group">
        <button type="button" class="btn btn-default btn-xs" style="background-color: #eee; color: #000" disabled="disabled">
          <span class="glyphicon glyphicon-transfer"></span> <strong>SSH</strong>
        </button>
        <button type="button" class="btn btn-default btn-xs" disabled="disabled">
          <span>adsysprd101.webroots.fas.harvard.edu</span>
        </button>
      </div>
    </td>
  </tr>
<?php }} ?>
</table>
<script type="text/javascript">
  $('.line').peity('line');
  $('.bar').peity('bar');
  $('.pie').peity('pie');
  $('.w-tooltip').tooltip();
</script>