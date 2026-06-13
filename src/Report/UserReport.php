<?php

namespace Porthole\Report;

final class UserReport
{
    /**
     * @param list<UserReportRow> $rows
     */
    public function __construct(
        public readonly array $rows,
    ) {
    }

    public function asView(): UserReportView
    {
        return new UserReportView($this);
    }
}
