<?php
// Test override: records the variables it was given, and who rendered.
$GLOBALS["hestahub_test_vars"] = get_defined_vars();
echo "parent:" . basename( __FILE__ );
