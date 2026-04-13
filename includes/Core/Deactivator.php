<?php
namespace TMC\Core;

class Deactivator {
    public static function deactivate() {
        // Não removemos a tabela aqui — isso acontece só em uninstall.php.
        // Deactivate apenas suspende o plugin; dados devem permanecer.
    }
}
