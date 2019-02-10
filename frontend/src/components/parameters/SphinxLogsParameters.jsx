import React from 'react';
import Grid from "@material-ui/core/Grid/Grid";
import TextField from "@material-ui/core/TextField/TextField";
import Paper from "@material-ui/core/Paper/Paper";
import moment from 'moment';
import ClearableTextField from '../ClearableTextField';
import {Datepicker} from "../Datepicker";

export default class SphinxLogsParameters extends React.Component {
  state = {
    isFromTimeValid: true,
    isToTimeValid: true
  }

  filterParameters = (parameters) => {
    return Object.keys(parameters).reduce((acc, parameterName) => {
      if (['type', 'fromTime', 'toTime', 'textFilter', 'table', 'objectId'].indexOf(parameterName) !== -1) {
        acc[parameterName] = parameters[parameterName];
      }
      return acc;
    }, {});
  }

  validate = () => {
    const isFromTimeValid = this._validateDate('fromTime', 'isFromTimeValid');
    const isToTimeValid = this._validateDate('toTime', 'isToTimeValid');
    return isFromTimeValid && isToTimeValid;
  }

  _validateDate = (propertyName, validStateName) => {
    const value = this.props[propertyName];
    const isValid = (value && moment(value).isValid());
    this.setState({
      [validStateName]: isValid
    })

    return isValid;
  }

  render() {
    const { textFilter, objectId, table, fromTime, toTime, onChange, onClear, className: classNameProp, onTextFilterChange } = this.props;
    const { isFromTimeValid, isToTimeValid } = this.state;
    const InvalidDateMessage = () => (<span>Date is missing or invalid</span>);
    return (
      <Paper elevation={1} className={classNameProp}>
        <Grid container spacing={16} >
          <Grid item xs={4}>
              <Datepicker
                  keyboard
                  name="fromTime"
                  label="From Time"
                  format={"YYYY-MM-DD hh:mm"}
                  invalidDateMessage={<InvalidDateMessage />}
                  fullWidth
                  onBlur={() => this._validateDate("fromTime", "isFromTimeValid")}
                  value={fromTime}
                  onChange={onChange}
              />
          </Grid>
          <Grid item xs={4}>
              <Datepicker
                  fullWidth
                  keyboard
                  name="toTime"
                  label="To Time"
                  value={toTime}
                  onChange={onChange}
                  invalidDateMessage={<InvalidDateMessage />}
                  onBlur={() => this._validateDate("toTime", "isToTimeValid")}
                  InputLabelProps={{
                      shrink: true
                  }}
              />
          </Grid>
          <Grid item xs={4}>
            <ClearableTextField
              fullWidth
              onClear={() => onTextFilterChange("")}
              label="Search Criteria"
              name={'textFilter'}
              value={textFilter.text}
              onChange={(e) => onTextFilterChange(e.target.value)}
            />
          </Grid>
          <Grid item xs={4}>
            <ClearableTextField fullWidth
                       name="table"
                       onClear={onClear}
                       label="Table"
                       value={table}
                       onChange={onChange}
                       InputLabelProps={{
                         shrink: true,
                       }}
            />
          </Grid>
          <Grid item xs={4}>
            <ClearableTextField
              fullWidth
              onClear={onClear}
              name="objectId"
              label="Object ID"
              value={objectId}
              onChange={onChange}
              InputLabelProps={{
                shrink: true,
              }}
            />
          </Grid>
        </Grid>
      </Paper>
    )
  }
}
