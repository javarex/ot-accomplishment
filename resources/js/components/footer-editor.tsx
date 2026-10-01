import { useRef } from 'react';
import { Button } from '@/components/ui/button';

export default function FooterEditor({
    initialHtml,
    onChange,
}: {
    initialHtml: string;
    onChange: (html: string) => void;
}) {
    const editor = useRef<HTMLDivElement>(null);
    const selection = useRef<Range | null>(null);
    const initial = useRef(initialHtml);

    function rememberSelection() {
        const current = window.getSelection();
        if (
            current?.rangeCount &&
            editor.current?.contains(current.anchorNode)
        ) {
            selection.current = current.getRangeAt(0).cloneRange();
        }
    }

    function format(command: string, value?: string) {
        editor.current?.focus();
        if (selection.current) {
            const current = window.getSelection();
            current?.removeAllRanges();
            current?.addRange(selection.current);
        }
        document.execCommand(command, false, value);
        editor.current?.querySelectorAll('font[size]').forEach((font) => {
            const sizes = [
                '8pt',
                '10pt',
                '12pt',
                '14pt',
                '18pt',
                '24pt',
                '36pt',
            ];
            (font as HTMLElement).style.fontSize =
                sizes[Number(font.getAttribute('size')) - 1];
        });
        rememberSelection();
        onChange(editor.current?.innerHTML ?? '');
    }

    return (
        <div className="rounded-md border bg-background">
            <div
                role="toolbar"
                aria-label="Footer formatting"
                className="flex flex-wrap items-center gap-2 border-b p-2"
            >
                {[
                    ['bold', 'Bold'],
                    ['italic', 'Italic'],
                    ['underline', 'Underline'],
                    ['justifyLeft', 'Left'],
                    ['justifyCenter', 'Center'],
                    ['justifyRight', 'Right'],
                    ['justifyFull', 'Justify'],
                    ['removeFormat', 'Clear formatting'],
                ].map(([command, label]) => (
                    <Button
                        key={command}
                        type="button"
                        variant="outline"
                        size="sm"
                        onMouseDown={(event) => event.preventDefault()}
                        onClick={() => format(command)}
                    >
                        {label}
                    </Button>
                ))}
                <select
                    aria-label="Footer font"
                    defaultValue=""
                    className="rounded-md border bg-background p-2 text-sm"
                    onChange={(event) => format('fontName', event.target.value)}
                >
                    <option value="" disabled>
                        Font
                    </option>
                    {[
                        'Times New Roman',
                        'Arial',
                        'DejaVu Sans',
                        'Courier New',
                    ].map((font) => (
                        <option key={font}>{font}</option>
                    ))}
                </select>
                <select
                    aria-label="Footer font size"
                    defaultValue=""
                    className="rounded-md border bg-background p-2 text-sm"
                    onChange={(event) => format('fontSize', event.target.value)}
                >
                    <option value="" disabled>
                        Size
                    </option>
                    {[8, 10, 12, 14, 18, 24, 36].map((size, index) => (
                        <option key={size} value={index + 1}>
                            {size} pt
                        </option>
                    ))}
                </select>
            </div>
            <div
                id="footer_text"
                ref={editor}
                contentEditable
                suppressContentEditableWarning
                role="textbox"
                aria-label="Footer text"
                aria-multiline="true"
                className="min-h-36 p-3 text-sm focus:outline-2 focus:outline-ring"
                style={{
                    fontFamily: 'Times New Roman',
                    fontSize: '8pt',
                    textAlign: 'center',
                }}
                dangerouslySetInnerHTML={{ __html: initial.current }}
                onInput={() => onChange(editor.current?.innerHTML ?? '')}
                onMouseUp={rememberSelection}
                onKeyUp={rememberSelection}
                onBlur={rememberSelection}
                onPaste={(event) => {
                    event.preventDefault();
                    document.execCommand(
                        'insertText',
                        false,
                        event.clipboardData.getData('text/plain'),
                    );
                    onChange(editor.current?.innerHTML ?? '');
                }}
            />
            <p className="border-t p-2 text-xs text-muted-foreground">
                Select text to format it. The footer image appears above this
                text.
            </p>
        </div>
    );
}
