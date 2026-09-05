#!/usr/bin/env node

/**
 * Extracts v2_notice and v2_knowledge rows from one or more mysqldump .sql.gz
 * files. The JSON output is consumed by import-content-site-data.php.
 *
 * Example:
 * node extract-content-site-data.js \
 *   --site 1 --dump /backups/chunqiux.sql.gz \
 *   --site 2 --dump /backups/jichang.sql.gz \
 *   --site 3 --dump /backups/yzjiasu.sql.gz \
 *   --output /tmp/content-site-data.json
 */

const fs = require('fs');
const zlib = require('zlib');

function usage(message) {
  if (message) console.error(message);
  console.error('Usage: node extract-content-site-data.js --site <1|2|3> --dump <dump.sql.gz> [... ] --output <output.json>');
  process.exit(1);
}

function parseArguments(argv) {
  const sources = [];
  let output = null;
  let pendingSite = null;

  for (let index = 0; index < argv.length; index += 1) {
    const token = argv[index];
    if (token === '--site') {
      pendingSite = Number(argv[++index]);
      if (!Number.isInteger(pendingSite) || ![1, 2, 3].includes(pendingSite)) usage('site 必须是 1、2 或 3');
    } else if (token === '--dump') {
      const dump = argv[++index];
      if (!pendingSite || !dump) usage('--dump 前必须提供 --site');
      sources.push({ siteId: pendingSite, dump });
      pendingSite = null;
    } else if (token === '--output') {
      output = argv[++index];
    } else {
      usage(`未知参数: ${token}`);
    }
  }

  if (pendingSite || sources.length === 0 || !output) usage();
  return { sources, output };
}

function decodeMysqlString(value) {
  let result = '';
  for (let index = 0; index < value.length; index += 1) {
    const char = value[index];
    if (char !== '\\' || index + 1 === value.length) {
      result += char;
      continue;
    }

    index += 1;
    const escaped = value[index];
    const replacements = { '0': '\0', b: '\b', n: '\n', r: '\r', t: '\t', Z: '\x1a' };
    result += Object.prototype.hasOwnProperty.call(replacements, escaped) ? replacements[escaped] : escaped;
  }
  return result;
}

function parseTuples(valuesSql) {
  const tuples = [];
  let index = 0;

  const skipWhitespace = () => {
    while (index < valuesSql.length && /\s/.test(valuesSql[index])) index += 1;
  };

  while (index < valuesSql.length) {
    skipWhitespace();
    if (valuesSql[index] === ',') {
      index += 1;
      continue;
    }
    if (valuesSql[index] !== '(') throw new Error(`无法解析 INSERT 元组，位置 ${index}`);
    index += 1;
    const row = [];

    while (index < valuesSql.length) {
      skipWhitespace();
      let value;
      if (valuesSql[index] === "'") {
        index += 1;
        let raw = '';
        let closed = false;
        while (index < valuesSql.length) {
          const char = valuesSql[index++];
          if (char === "'") {
            closed = true;
            break;
          }
          if (char === '\\' && index < valuesSql.length) {
            raw += char + valuesSql[index++];
          } else {
            raw += char;
          }
        }
        if (!closed) throw new Error('INSERT 字符串未闭合');
        value = decodeMysqlString(raw);
      } else {
        const start = index;
        while (index < valuesSql.length && valuesSql[index] !== ',' && valuesSql[index] !== ')') index += 1;
        const token = valuesSql.slice(start, index).trim();
        value = token.toUpperCase() === 'NULL' ? null : token;
      }
      row.push(value);
      skipWhitespace();
      if (valuesSql[index] === ',') {
        index += 1;
        continue;
      }
      if (valuesSql[index] === ')') {
        index += 1;
        break;
      }
      throw new Error(`INSERT 字段分隔符异常，位置 ${index}`);
    }
    tuples.push(row);
  }

  return tuples;
}

function extractInsertStatements(sql, table) {
  const prefix = `INSERT INTO \`${table}\` VALUES `;
  const statements = [];
  let start = 0;
  while ((start = sql.indexOf(prefix, start)) !== -1) {
    const valuesStart = start + prefix.length;
    let index = valuesStart;
    let quoted = false;
    while (index < sql.length) {
      const char = sql[index];
      if (quoted && char === '\\') {
        index += 2;
        continue;
      }
      if (char === "'") quoted = !quoted;
      if (!quoted && char === ';') break;
      index += 1;
    }
    if (index >= sql.length) throw new Error(`${table} 的 INSERT 语句未结束`);
    statements.push(sql.slice(valuesStart, index));
    start = index + 1;
  }
  return statements;
}

function asInteger(value, field) {
  if (value === null || value === '') return null;
  const number = Number(value);
  if (!Number.isInteger(number)) throw new Error(`${field} 不是整数: ${value}`);
  return number;
}

function decodeDump(file) {
  const buffer = fs.readFileSync(file);
  return zlib.gunzipSync(buffer).toString('utf8');
}

function extractRows(sql, table) {
  const statements = extractInsertStatements(sql, table);
  return statements.flatMap(parseTuples);
}

function normalizeNotice(row) {
  if (row.length !== 8) throw new Error(`v2_notice 字段数错误: ${row.length}`);
  return {
    source_id: asInteger(row[0], 'notice.id'),
    title: row[1],
    content: row[2],
    show: asInteger(row[3], 'notice.show') || 0,
    img_url: row[4],
    tags: row[5],
    created_at: asInteger(row[6], 'notice.created_at'),
    updated_at: asInteger(row[7], 'notice.updated_at'),
  };
}

function normalizeKnowledge(row) {
  if (row.length !== 9) throw new Error(`v2_knowledge 字段数错误: ${row.length}`);
  return {
    source_id: asInteger(row[0], 'knowledge.id'),
    language: row[1],
    category: row[2],
    title: row[3],
    body: row[4],
    sort: asInteger(row[5], 'knowledge.sort'),
    show: asInteger(row[6], 'knowledge.show') || 0,
    created_at: asInteger(row[7], 'knowledge.created_at'),
    updated_at: asInteger(row[8], 'knowledge.updated_at'),
  };
}

function main() {
  const { sources, output } = parseArguments(process.argv.slice(2));
  const data = {
    schema_version: 1,
    generated_at: new Date().toISOString(),
    sites: {},
  };

  for (const { siteId, dump } of sources) {
    if (data.sites[siteId]) usage(`site_id=${siteId} 重复提供`);
    const sql = decodeDump(dump);
    const notices = extractRows(sql, 'v2_notice').map(normalizeNotice);
    const knowledges = extractRows(sql, 'v2_knowledge').map(normalizeKnowledge);
    data.sites[siteId] = { notices, knowledges };
    console.error(`site_id=${siteId}: 公告 ${notices.length} 条，教程 ${knowledges.length} 条`);
  }

  fs.writeFileSync(output, `${JSON.stringify(data, null, 2)}\n`, 'utf8');
}

main();
