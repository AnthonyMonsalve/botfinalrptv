<#
.SYNOPSIS
    Empaqueta los plugins de DatAvenger listos para subir a WordPress.

.DESCRIPTION
    Lee la version de la cabecera del plugin, la sube si se le pide, la deja
    sincronizada en todos los sitios donde aparece dentro del archivo principal
    y genera el .zip en dist/ con la carpeta raiz correcta.

    Los .zip NO se versionan en git: son material de despliegue, no de codigo.

.PARAMETER Plugin
    'bot', 'portal' o 'todo' (por defecto).

.PARAMETER Bump
    'patch', 'minor' o 'major'. Sin esto se empaqueta con la version actual.

.PARAMETER Tag
    Crea ademas la etiqueta git <slug>-v<version>.

.PARAMETER Limpiar
    Borra dist/ antes de empezar.

.EXAMPLE
    .\build.ps1
    Empaqueta los dos plugins con la version que ya tienen.

.EXAMPLE
    .\build.ps1 portal -Bump patch
    Sube el portal de 1.9.1 a 1.9.2, lo escribe en la cabecera y en su
    constante de version, y genera dist\datavenger-portal-electrico-1.9.2.zip

.EXAMPLE
    .\build.ps1 -Bump minor -Tag
    Sube la version menor de ambos, empaqueta y etiqueta en git.
#>

[CmdletBinding()]
param(
    [Parameter(Position = 0)]
    [ValidateSet('bot', 'portal', 'todo')]
    [string]$Plugin = 'todo',

    [ValidateSet('patch', 'minor', 'major')]
    [string]$Bump,

    [switch]$Tag,
    [switch]$Limpiar
)

$ErrorActionPreference = 'Stop'
$raiz = $PSScriptRoot

# Los dos entregables. 'slug' es el nombre de la carpeta dentro del zip y, por
# tanto, el nombre con el que WordPress instala el plugin: cambiarlo en un sitio
# ya publicado instalaria una copia nueva en vez de actualizar la existente.
$PLUGINS = @(
    @{
        clave     = 'bot'
        slug      = 'botfinalrptv'
        principal = 'bot.php'
        nombre    = 'DatAvenger Twilio Responder (bot de WhatsApp)'
    },
    @{
        clave     = 'portal'
        slug      = 'datavenger-portal-electrico'
        principal = 'datavenger-portal-electrico.php'
        nombre    = 'Portal Reporte Electrico (web publica)'
    }
)

# Lo que nunca viaja en el zip. Los *_log.txt y twilio.txt los escribe el propio
# plugin en produccion: incluirlos sobrescribiria los del servidor con los de
# nuestra maquina, ademas de engordar el paquete varios megas.
$EXCLUIR_CARPETAS = @('.git', '.github', 'node_modules', 'dist', '.vscode', '.idea')
$EXCLUIR_ARCHIVOS = @(
    '*.zip', '*.log', '*_log.txt', 'twilio.txt', 'validate_info_log.txt',
    '.DS_Store', 'Thumbs.db', 'composer.phar', 'docker-compose.yml', '.gitignore'
)

# --------------------------------------------------------------------------
# Utilidades
# --------------------------------------------------------------------------

function Leer-Texto([string]$ruta) {
    # Sin BOM: un solo byte de mas antes de <?php rompe las cabeceras de PHP.
    return [System.IO.File]::ReadAllText($ruta, [System.Text.Encoding]::UTF8)
}

function Escribir-Texto([string]$ruta, [string]$texto) {
    $utf8SinBom = New-Object System.Text.UTF8Encoding($false)
    [System.IO.File]::WriteAllText($ruta, $texto, $utf8SinBom)
}

function Obtener-Version([string]$rutaPrincipal) {
    $texto = Leer-Texto $rutaPrincipal

    if ($texto -match '(?m)^[\s\*]*Version:\s*([0-9]+\.[0-9]+\.[0-9]+)') {
        return $Matches[1]
    }

    throw "No encuentro la cabecera 'Version:' en $rutaPrincipal"
}

function Subir-Version([string]$version, [string]$tipo) {
    $p = $version.Split('.')
    $mayor = [int]$p[0]; $menor = [int]$p[1]; $parche = [int]$p[2]

    switch ($tipo) {
        'major' { $mayor++; $menor = 0; $parche = 0 }
        'minor' { $menor++; $parche = 0 }
        'patch' { $parche++ }
    }

    return "$mayor.$menor.$parche"
}

function Escribir-Version([string]$rutaPrincipal, [string]$vieja, [string]$nueva) {
    $texto = Leer-Texto $rutaPrincipal
    $escapada = [regex]::Escape($vieja)

    # 1. La cabecera que lee WordPress.
    $texto = $texto -replace "(?m)^([\s\*]*Version:\s*)$escapada\s*$", "`${1}$nueva"

    # 2. Las constantes que reflejan esa misma version (DVPE_VERSION y
    #    compania). Solo se tocan las que coinciden EXACTAMENTE con la version
    #    vieja: asi las que llevan su propia numeracion, como la version de la
    #    API o la del esquema de base de datos, se quedan donde estan.
    $texto = $texto -replace "(define\(\s*'[A-Z_]+'\s*,\s*')$escapada(')", "`${1}$nueva`${2}"

    Escribir-Texto $rutaPrincipal $texto
}

function Borrar-Si-Puedes([string]$ruta) {
    # Windows suele retener unos instantes los archivos recien escritos (el
    # antivirus los esta revisando). Se reintenta y, si aun asi no se puede, no
    # se aborta: el zip ya esta hecho y la carpeta temporal es desechable.
    for ($i = 0; $i -lt 6; $i++) {
        if (-not (Test-Path $ruta)) { return $true }

        try {
            Remove-Item $ruta -Recurse -Force -ErrorAction Stop
            return $true
        } catch {
            Start-Sleep -Milliseconds 700
        }
    }

    return -not (Test-Path $ruta)
}

function Formatear-Peso([long]$bytes) {
    if ($bytes -ge 1MB) { return "{0:N1} MB" -f ($bytes / 1MB) }
    return "{0:N0} KB" -f ($bytes / 1KB)
}

# --------------------------------------------------------------------------
# Empaquetado
# --------------------------------------------------------------------------

function Empaquetar($def) {
    $origen = Join-Path $raiz "plugins\$($def.slug)"

    if (-not (Test-Path $origen)) {
        throw "No existe la carpeta $origen"
    }

    $rutaPrincipal = Join-Path $origen $def.principal
    $version = Obtener-Version $rutaPrincipal

    if ($Bump) {
        $nueva = Subir-Version $version $Bump
        Escribir-Version $rutaPrincipal $version $nueva
        Write-Host ("  version  {0} -> {1}" -f $version, $nueva) -ForegroundColor Yellow
        $version = $nueva
    } else {
        Write-Host ("  version  {0}" -f $version)
    }

    # Se copia a una carpeta temporal con el nombre del slug para que esa sea la
    # raiz del zip, que es la que determina donde instala WordPress el plugin.
    # El armado va al temporal del sistema y no a dist/, para no dejar restos
    # dentro del proyecto si algo se interrumpe a mitad.
    $temporal = Join-Path ([System.IO.Path]::GetTempPath()) "datavenger-build"
    $stage = Join-Path $temporal $def.slug

    Borrar-Si-Puedes $temporal | Out-Null
    New-Item -ItemType Directory -Path $stage -Force | Out-Null

    $argumentos = @($origen, $stage, '/E', '/NFL', '/NDL', '/NJH', '/NJS', '/NP', '/R:1', '/W:1')
    $argumentos += '/XD'; $argumentos += $EXCLUIR_CARPETAS
    $argumentos += '/XF'; $argumentos += $EXCLUIR_ARCHIVOS

    & robocopy @argumentos | Out-Null

    # robocopy usa 0-7 para "todo bien"; de 8 en adelante es error de verdad.
    if ($LASTEXITCODE -ge 8) {
        throw "robocopy fallo con codigo $LASTEXITCODE"
    }

    $global:LASTEXITCODE = 0

    $archivos = (Get-ChildItem $stage -Recurse -File).Count
    $zip = Join-Path $raiz ("dist\{0}-{1}.zip" -f $def.slug, $version)

    if (Test-Path $zip) { Remove-Item $zip -Force }

    # Se usa .NET y no Compress-Archive: con las ~2.000 dependencias del SDK de
    # Twilio, el cmdlet de PowerShell tarda minutos y este tarda segundos. El
    # ultimo parametro incluye la carpeta como raiz del zip, que es justo lo que
    # WordPress necesita para instalar en el directorio correcto.
    Add-Type -AssemblyName System.IO.Compression.FileSystem

    [System.IO.Compression.ZipFile]::CreateFromDirectory(
        $stage,
        $zip,
        [System.IO.Compression.CompressionLevel]::Optimal,
        $true
    )

    Borrar-Si-Puedes $temporal | Out-Null

    if ($Tag) {
        $etiqueta = "$($def.slug)-v$version"

        & git -C $raiz tag $etiqueta 2>&1 | Out-Null

        if ($LASTEXITCODE -eq 0) {
            Write-Host "  etiqueta $etiqueta" -ForegroundColor DarkGray
        } else {
            Write-Host "  etiqueta $etiqueta ya existia" -ForegroundColor DarkGray
        }

        $global:LASTEXITCODE = 0
    }

    return [PSCustomObject]@{
        Plugin   = $def.slug
        Version  = $version
        Archivos = $archivos
        Peso     = Formatear-Peso (Get-Item $zip).Length
        Zip      = "dist\" + (Split-Path $zip -Leaf)
    }
}

# --------------------------------------------------------------------------
# Ejecucion
# --------------------------------------------------------------------------

if ($Limpiar -and (Test-Path (Join-Path $raiz 'dist'))) {
    Remove-Item (Join-Path $raiz 'dist') -Recurse -Force
    Write-Host "dist/ borrado" -ForegroundColor DarkGray
}

New-Item -ItemType Directory -Path (Join-Path $raiz 'dist') -Force | Out-Null

$seleccion = if ($Plugin -eq 'todo') { $PLUGINS } else { $PLUGINS | Where-Object { $_.clave -eq $Plugin } }

Write-Host ""
Write-Host "DatAvenger - empaquetado" -ForegroundColor Cyan
Write-Host "------------------------" -ForegroundColor Cyan

$resultados = @()

foreach ($def in $seleccion) {
    Write-Host ""
    Write-Host $def.nombre -ForegroundColor White
    $resultados += Empaquetar $def
}

Write-Host ""
$resultados | Format-Table -AutoSize
Write-Host "Listos para subir desde el panel de WordPress (Plugins > Anadir > Subir)." -ForegroundColor Green

if ($Tag) {
    Write-Host "Recuerda: git push --tags" -ForegroundColor DarkGray
}

Write-Host ""
