Add-Type -AssemblyName System.Drawing

$root = "C:\xampp\htdocs\FESTIVAL_CALOUROS2\mobile_capacitor\android\app\src\main\res"
$blue = [System.Drawing.Color]::FromArgb(255, 14, 56, 157)
$blueDark = [System.Drawing.Color]::FromArgb(255, 7, 37, 122)
$gold = [System.Drawing.Color]::FromArgb(255, 255, 200, 26)
$white = [System.Drawing.Color]::White
$textGray = [System.Drawing.Color]::FromArgb(255, 102, 112, 138)

function New-GraphicsContext {
    param(
        [int]$Width,
        [int]$Height,
        [bool]$Transparent = $false
    )

    $bitmap = New-Object System.Drawing.Bitmap $Width, $Height
    if ($Transparent) {
        $bitmap.MakeTransparent()
    }

    $graphics = [System.Drawing.Graphics]::FromImage($bitmap)
    $graphics.SmoothingMode = [System.Drawing.Drawing2D.SmoothingMode]::AntiAlias
    $graphics.InterpolationMode = [System.Drawing.Drawing2D.InterpolationMode]::HighQualityBicubic
    $graphics.PixelOffsetMode = [System.Drawing.Drawing2D.PixelOffsetMode]::HighQuality
    $graphics.TextRenderingHint = [System.Drawing.Text.TextRenderingHint]::AntiAliasGridFit

    return @{ Bitmap = $bitmap; Graphics = $graphics }
}

function Save-Png {
    param(
        [System.Drawing.Bitmap]$Bitmap,
        [string]$Path
    )

    $directory = Split-Path -Parent $Path
    if (-not (Test-Path $directory)) {
        New-Item -Path $directory -ItemType Directory | Out-Null
    }

    $tempPath = $Path + ".tmp.png"
    if (Test-Path $tempPath) {
        Remove-Item -Path $tempPath -Force
    }

    $Bitmap.Save($tempPath, [System.Drawing.Imaging.ImageFormat]::Png)

    if (Test-Path $Path) {
        Remove-Item -Path $Path -Force
    }

    Move-Item -Path $tempPath -Destination $Path -Force
}

function Draw-BrandArc {
    param(
        [System.Drawing.Graphics]$Graphics,
        [float]$Width,
        [float]$Height
    )

    $pen = New-Object System.Drawing.Pen $gold, ([Math]::Max(8, $Height * 0.07))
    $Graphics.DrawArc($pen, $Width * 0.12, $Height * 0.06, $Width * 0.72, $Height * 0.34, 208, 108)
    $pen.Dispose()
}

function Draw-LogoLetters {
    param(
        [System.Drawing.Graphics]$Graphics,
        [float]$Width,
        [float]$Height,
        [float]$FontSize,
        [bool]$Centered = $true
    )

    $font = New-Object System.Drawing.Font "Segoe UI", $FontSize, ([System.Drawing.FontStyle]::Bold)
    $brush = New-Object System.Drawing.SolidBrush $white
    $format = New-Object System.Drawing.StringFormat
    if ($Centered) {
        $format.Alignment = [System.Drawing.StringAlignment]::Center
    }
    $format.LineAlignment = [System.Drawing.StringAlignment]::Center
    $Graphics.DrawString("SJ", $font, $brush, (New-Object System.Drawing.RectangleF ($Width * 0.18), ($Height * 0.26), ($Width * 0.64), ($Height * 0.44)), $format)
    $brush.Dispose()
    $font.Dispose()
    $format.Dispose()
}

function Render-LauncherIcon {
    param(
        [int]$Size,
        [string]$Path
    )

    $ctx = New-GraphicsContext -Width $Size -Height $Size
    $bitmap = $ctx.Bitmap
    $graphics = $ctx.Graphics

    $graphics.Clear($blue)
    $graphics.FillEllipse((New-Object System.Drawing.SolidBrush $gold), -($Size * 0.28), $Size * 0.66, $Size * 0.66, $Size * 0.66)
    Draw-BrandArc -Graphics $graphics -Width $Size -Height $Size
    Draw-LogoLetters -Graphics $graphics -Width $Size -Height $Size -FontSize ($Size * 0.34)

    Save-Png -Bitmap $bitmap -Path $Path
    $graphics.Dispose()
    $bitmap.Dispose()
}

function Render-LauncherForeground {
    param(
        [int]$Size,
        [string]$Path
    )

    $ctx = New-GraphicsContext -Width $Size -Height $Size -Transparent $true
    $bitmap = $ctx.Bitmap
    $graphics = $ctx.Graphics

    $shadowBrush = New-Object System.Drawing.SolidBrush ([System.Drawing.Color]::FromArgb(38, 0, 0, 0))
    $graphics.FillEllipse($shadowBrush, $Size * 0.16, $Size * 0.18, $Size * 0.68, $Size * 0.68)
    $shadowBrush.Dispose()

    $circleBrush = New-Object System.Drawing.SolidBrush $blue
    $graphics.FillEllipse($circleBrush, $Size * 0.14, $Size * 0.16, $Size * 0.68, $Size * 0.68)
    $circleBrush.Dispose()

    Draw-BrandArc -Graphics $graphics -Width ($Size * 0.92) -Height ($Size * 0.9)
    Draw-LogoLetters -Graphics $graphics -Width $Size -Height $Size -FontSize ($Size * 0.24)

    Save-Png -Bitmap $bitmap -Path $Path
    $graphics.Dispose()
    $bitmap.Dispose()
}

function Render-Splash {
    param(
        [int]$Width,
        [int]$Height,
        [string]$Path
    )

    $ctx = New-GraphicsContext -Width $Width -Height $Height
    $bitmap = $ctx.Bitmap
    $graphics = $ctx.Graphics

    $graphics.Clear($white)

    $leftWidth = [Math]::Max([int]($Width * 0.24), 220)
    $leftBrush = New-Object System.Drawing.Drawing2D.LinearGradientBrush (
        (New-Object System.Drawing.Rectangle 0, 0, $leftWidth, $Height),
        $blue,
        $blueDark,
        [System.Drawing.Drawing2D.LinearGradientMode]::Vertical
    )
    $graphics.FillRectangle($leftBrush, 0, 0, $leftWidth, $Height)
    $leftBrush.Dispose()

    $goldBrush = New-Object System.Drawing.SolidBrush $gold
    $graphics.FillEllipse($goldBrush, -($leftWidth * 0.4), $Height * 0.82, $leftWidth * 1.15, $leftWidth * 1.15)
    $goldBrush.Dispose()

    $brandFont = New-Object System.Drawing.Font "Segoe UI", ([Math]::Max(18, $Height * 0.04)), ([System.Drawing.FontStyle]::Bold)
    $brandBrush = New-Object System.Drawing.SolidBrush $white
    $graphics.DrawString("Sesc", $brandFont, $brandBrush, $leftWidth * 0.1, $Height * 0.15)
    $brandBrush.Dispose()
    $brandFont.Dispose()

    $subFont = New-Object System.Drawing.Font "Segoe UI", ([Math]::Max(8, $Height * 0.012)), ([System.Drawing.FontStyle]::Regular)
    $subBrush = New-Object System.Drawing.SolidBrush $white
    $graphics.DrawString("Jurados", $subFont, $subBrush, $leftWidth * 0.1, $Height * 0.33)
    $subBrush.Dispose()
    $subFont.Dispose()

    $lineBrush = New-Object System.Drawing.SolidBrush $gold
    $graphics.FillRectangle($lineBrush, $leftWidth * 0.1, $Height * 0.43, $leftWidth * 0.35, [Math]::Max(3, $Height * 0.005))
    $lineBrush.Dispose()

    $titleFont = New-Object System.Drawing.Font "Segoe UI", ([Math]::Max(20, $Height * 0.05)), ([System.Drawing.FontStyle]::Bold)
    $titleBrush = New-Object System.Drawing.SolidBrush $blue
    $graphics.DrawString("Bem-vindo", $titleFont, $titleBrush, $leftWidth + ($Width * 0.08), $Height * 0.32)
    $titleBrush.Dispose()
    $titleFont.Dispose()

    $bodyFont = New-Object System.Drawing.Font "Segoe UI", ([Math]::Max(12, $Height * 0.024)), ([System.Drawing.FontStyle]::Regular)
    $bodyBrush = New-Object System.Drawing.SolidBrush $textGray
    $graphics.DrawString("Notas para eventos em tablets.", $bodyFont, $bodyBrush, $leftWidth + ($Width * 0.08), $Height * 0.46)
    $bodyBrush.Dispose()
    $bodyFont.Dispose()

    $accentBrush = New-Object System.Drawing.SolidBrush ([System.Drawing.Color]::FromArgb(255, 238, 243, 255))
    $graphics.FillRectangle($accentBrush, $leftWidth + $Width * 0.08, $Height * 0.58, $Width * 0.18, [Math]::Max(8, $Height * 0.012))
    $accentBrush.Dispose()

    Save-Png -Bitmap $bitmap -Path $Path
    $graphics.Dispose()
    $bitmap.Dispose()
}

$launcherSizes = @{
    "mipmap-mdpi" = 48
    "mipmap-hdpi" = 72
    "mipmap-xhdpi" = 96
    "mipmap-xxhdpi" = 144
    "mipmap-xxxhdpi" = 192
}

foreach ($entry in $launcherSizes.GetEnumerator()) {
    Render-LauncherIcon -Size $entry.Value -Path (Join-Path $root ($entry.Key + "\ic_launcher.png"))
    Render-LauncherIcon -Size $entry.Value -Path (Join-Path $root ($entry.Key + "\ic_launcher_round.png"))
    Render-LauncherForeground -Size $entry.Value -Path (Join-Path $root ($entry.Key + "\ic_launcher_foreground.png"))
}

$splashTargets = Get-ChildItem -Path $root -Recurse -Filter splash.png
foreach ($target in $splashTargets) {
    $existing = [System.Drawing.Image]::FromFile($target.FullName)
    $width = $existing.Width
    $height = $existing.Height
    $existing.Dispose()
    Render-Splash -Width $width -Height $height -Path $target.FullName
}
