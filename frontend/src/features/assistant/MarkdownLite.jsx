import { Fragment } from 'react'

/**
 * The small markdown subset the assistant writes: paragraphs, "- " and "1. "
 * lists, **bold**, _italic_ and `code`. Rendered as React elements — never as
 * HTML — so nothing in an answer can inject markup.
 */
export function MarkdownLite({ text }) {
  const blocks = []
  let list = null
  for (const raw of String(text ?? '').split('\n')) {
    const line = raw.trimEnd()
    const bullet = line.match(/^\s*[-*•]\s+(.*)$/)
    const numbered = line.match(/^\s*(\d+)[.)]\s+(.*)$/)
    if (bullet || numbered) {
      const ordered = Boolean(numbered)
      if (!list || list.ordered !== ordered) {
        list = { type: 'list', ordered, items: [] }
        blocks.push(list)
      }
      list.items.push(bullet ? bullet[1] : numbered[2])
      continue
    }
    list = null
    if (line.trim() === '') {
      blocks.push({ type: 'break' })
    } else {
      const last = blocks[blocks.length - 1]
      if (last?.type === 'p') last.lines.push(line.trim())
      else blocks.push({ type: 'p', lines: [line.trim()] })
    }
  }

  return (
    <div className="flex flex-col gap-2">
      {blocks.filter((b) => b.type !== 'break').map((block, index) => {
        if (block.type === 'list') {
          const Tag = block.ordered ? 'ol' : 'ul'
          return (
            <Tag key={index} className={block.ordered ? 'flex list-decimal flex-col gap-1 pl-5' : 'flex list-disc flex-col gap-1 pl-5 marker:text-ink-3'}>
              {block.items.map((item, i) => <li key={i}><Inline text={item} /></li>)}
            </Tag>
          )
        }
        return (
          <p key={index}>
            {block.lines.map((line, i) => (
              <Fragment key={i}>
                {i > 0 && <br />}
                <Inline text={line} />
              </Fragment>
            ))}
          </p>
        )
      })}
    </div>
  )
}

function Inline({ text }) {
  const parts = []
  const pattern = /\*\*(.+?)\*\*|(?<![\p{L}\d])_(.+?)_(?![\p{L}\d])|`([^`]+)`/gu
  let last = 0
  for (const match of text.matchAll(pattern)) {
    if (match.index > last) parts.push(text.slice(last, match.index))
    if (match[1] !== undefined) parts.push(<strong key={match.index} className="font-semibold text-ink">{match[1]}</strong>)
    else if (match[2] !== undefined) parts.push(<em key={match.index} className="text-ink-2">{match[2]}</em>)
    else parts.push(<code key={match.index} className="rounded-sm bg-surface-2 px-1 font-mono text-[12px]">{match[3]}</code>)
    last = match.index + match[0].length
  }
  if (last < text.length) parts.push(text.slice(last))
  return parts
}
