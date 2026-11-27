<?php
// Loaded by WISECP's authenticated service tool loader, inside the existing theme.
declare(strict_types=1);
if (!isset($module, $data) || !is_array($data)) { http_response_code(403); return; }
echo ($admin_view??false)===true ? $module->admin_tool_html() : (string)($data['panelHtml']??'');
