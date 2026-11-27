# Exporta as tabelas do ManWinWin (.mdb) usadas pela importacao do MZ Manutencao para um .zip de CSVs.
# Use pelo exportar_manwinwin.bat (arraste o ManWinWin.mdb sobre ele).
# Execucao direta:  powershell -ExecutionPolicy Bypass -File exportar_manwinwin.ps1 -Mdb "C:\...\ManWinWin.mdb" [-Saida arquivo.zip] [-Senha senhaDoBanco]
# O banco e aberto somente para leitura; nada e alterado no ManWinWin.
param(
    [Parameter(Mandatory = $true)][string]$Mdb,
    [string]$Saida,
    [string]$Senha
)
$ErrorActionPreference = 'Stop'

$Mdb = $Mdb.Trim().Trim('"')
if (-not (Test-Path -LiteralPath $Mdb)) { Write-Host "Arquivo nao encontrado: $Mdb" -ForegroundColor Red; exit 1 }
$Mdb = (Resolve-Path -LiteralPath $Mdb).Path
if (-not $Saida) { $Saida = Join-Path (Split-Path -Parent $Mdb) ('manwinwin_' + (Get-Date -Format 'yyyyMMdd_HHmm') + '.zip') }

$tabelas = @('Objectos', 'LocaisObj', 'TipObjeto', 'Forneced', 'Especialidade', 'Funcionario', 'Cencusto', 'Sistemas',
             'Fichas', 'Calibra', 'TipTrabalho', 'PTs', 'Causas', 'MestreArtigos', 'Artigos', 'ARQ02')

$funcCols = @('CodFunCnr', 'NomFunCnr', 'Email_Fun', 'CodEspCld', 'CstEspCld', 'FlgActivo')

Add-Type -AssemblyName System.Data
$con = $null
$ultimoErro = ''
foreach ($p in @('Microsoft.ACE.OLEDB.16.0', 'Microsoft.ACE.OLEDB.12.0', 'Microsoft.Jet.OLEDB.4.0')) {
    try {
        # builder: caminho ou senha com ";" nao alteram a string de conexao
        $b = New-Object System.Data.OleDb.OleDbConnectionStringBuilder
        $b['Provider'] = $p
        $b['Data Source'] = $Mdb
        $b['Mode'] = 'Read'
        if ($Senha) { $b['Jet OLEDB:Database Password'] = $Senha }
        $c = New-Object System.Data.OleDb.OleDbConnection($b.ConnectionString)
        $c.Open()
        $con = $c
        Write-Host "Driver: $p ($([IntPtr]::Size * 8) bits)"
        break
    } catch {
        $msg = $_.Exception.Message
        if ($msg -notmatch 'not registered|nao esta registrado|n.o est. registrado') { $ultimoErro = $msg }
    }
}
if (-not $con) {
    if ($ultimoErro) {
        Write-Host "Nao foi possivel abrir o banco: $ultimoErro" -ForegroundColor Red
        Write-Host 'Feche o ManWinWin e tente de novo. Se o banco tiver senha, use -Senha.' -ForegroundColor Yellow
        exit 1
    }
    Write-Host "Nenhum driver do Access (Jet/ACE) neste PowerShell de $([IntPtr]::Size * 8) bits." -ForegroundColor Yellow
    exit 2
}

$existentes = @($con.GetSchema('Tables') | Where-Object { $_.TABLE_TYPE -eq 'TABLE' } | ForEach-Object { $_.TABLE_NAME })
if ($existentes -notcontains 'Objectos') { Write-Host 'Este arquivo nao parece ser um banco do ManWinWin (sem a tabela Objectos).' -ForegroundColor Red; $con.Close(); exit 1 }

$inv = [Globalization.CultureInfo]::InvariantCulture
$utf8 = New-Object System.Text.UTF8Encoding($false)
function Campo($v) {
    if ($null -eq $v -or $v -is [DBNull] -or $v -is [byte[]]) { return '' }
    if ($v -is [datetime]) { return $v.ToString('yyyy-MM-dd HH:mm:ss', $inv) }
    if ($v -is [bool]) { if ($v) { return '1' } else { return '0' } }
    if ($v -is [string]) { return '"' + $v.Replace('"', '""') + '"' }
    return [Convert]::ToString($v, $inv)
}

$tmp = Join-Path ([IO.Path]::GetTempPath()) ('mww_' + [guid]::NewGuid().ToString('N'))
New-Item -ItemType Directory -Path $tmp | Out-Null
try {
    foreach ($t in $tabelas) {
        if ($existentes -notcontains $t) { continue }
        $cmd = $con.CreateCommand()
        $cmd.CommandText = "SELECT * FROM [$t]"
        $r = $cmd.ExecuteReader()
        $sw = New-Object System.IO.StreamWriter((Join-Path $tmp "$t.csv"), $false, $utf8)
        try {
            # senhas e dados pessoais do ManWinWin nao sao exportados (a importacao nao usa)
            $idx = New-Object System.Collections.Generic.List[int]
            for ($i = 0; $i -lt $r.FieldCount; $i++) {
                $nomeCol = $r.GetName($i)
                if ($nomeCol -match '^(Snh|Senha|Pass|Pwd)') { continue }
                if ($t -eq 'Funcionario' -and $funcCols -notcontains $nomeCol) { continue }
                $idx.Add($i)
            }
            $cols = New-Object string[] $idx.Count
            for ($j = 0; $j -lt $idx.Count; $j++) { $cols[$j] = '"' + $r.GetName($idx[$j]) + '"' }
            $sw.WriteLine(($cols -join ','))
            $n = 0
            while ($r.Read()) {
                $vals = New-Object string[] $idx.Count
                for ($j = 0; $j -lt $idx.Count; $j++) { $vals[$j] = Campo ($r.GetValue($idx[$j])) }
                $sw.WriteLine(($vals -join ','))
                $n++
            }
        } finally { $sw.Close(); $r.Close() }
        Write-Host ('  {0,-15} {1} linha(s)' -f $t, $n)
    }
    $con.Close()
    if (Test-Path -LiteralPath $Saida) { Remove-Item -LiteralPath $Saida -Force }
    Add-Type -AssemblyName System.IO.Compression.FileSystem
    [IO.Compression.ZipFile]::CreateFromDirectory($tmp, $Saida)
} finally {
    Remove-Item -LiteralPath $tmp -Recurse -Force -ErrorAction SilentlyContinue
}
Write-Host ''
Write-Host "Gerado: $Saida" -ForegroundColor Green
Write-Host 'Envie este .zip em Configuracoes > Importacao do MZ Manutencao.'
exit 0
