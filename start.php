#!/bin/env php
<?php
shell_exec("su git -c 'php -S 0.0.0.0:8080 -t public'");
