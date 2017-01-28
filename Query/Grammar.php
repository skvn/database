<?php

namespace Skvn\Database\Query;


class Grammar
{
    public function compileInsert($table, array $values)
    {
        $table = $this->wrapTable($table);

        if (! is_array(reset($values))) {
            $values = [$values];
        }

        $columns = $this->columnize(array_keys(reset($values)));

        $paramArr = array_map(function($v){
            return '(' . $this->parameterize($v) . ')';
        }, $values);
        $parameters = implode(', ', $paramArr);

        return "insert into $table ($columns) values $parameters";
    }

    public function wrapTable($table)
    {
        if (! $this->isExpression($table)) {
            return $this->wrap($table, true);
        }

        return $this->getValue($table);
    }

    public function isExpression($value)
    {
        return $value instanceof Expression;
    }

    public function wrap($value, $prefixAlias = false)
    {
        if ($this->isExpression($value)) {
            return $this->getValue($value);
        }

        if (strpos(strtolower($value), ' as ') !== false) {
            return $this->wrapAliasedValue($value, $prefixAlias);
        }

        return $this->wrapSegments(explode('.', $value));
    }

    public function getValue($expression)
    {
        return $expression->getValue();
    }

    protected function wrapAliasedValue($value, $prefixAlias = false)
    {
        $segments = preg_split('/\s+as\s+/i', $value);

        if ($prefixAlias) {
            $segments[1] = $segments[1];
        }

        return $this->wrap(
            $segments[0]).' as '.$this->wrapValue($segments[1]
        );
    }

    protected function wrapValue($value)
    {
        if ($value === '*') {
            return $value;
        }

        return '`'.str_replace('`', '``', $value).'`';
    }

    protected function wrapSegments($segments)
    {
        $segments = array_map(function($v, $k) use ($segments) {
            return $k == 0 && count($segments) > 1 ? $this->wrapTable($v) : $this->wrapValue($v);
        }, $segments, array_keys($segments));
        return implode('.', $segments);
    }

    public function columnize(array $columns)
    {
        return implode(', ', array_map([$this, 'wrap'], $columns));
    }

    public function parameterize(array $values)
    {
        return implode(', ', array_map([$this, 'parameter'], $values));
    }

    public function parameter($value)
    {
        return $this->isExpression($value) ? $this->getValue($value) : '?';
    }







}
