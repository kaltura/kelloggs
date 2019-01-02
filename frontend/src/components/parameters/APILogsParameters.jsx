import React from 'react';
import Grid from "@material-ui/core/Grid/Grid";
import TextField from "@material-ui/core/TextField/TextField";
import Paper from "@material-ui/core/Paper/Paper";
import moment from 'moment';

export default class APILogsParameters extends React.Component {
  state = {
    isFromTimeValid: true,
    isToTimeValid: true
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
    const { textFilter, session, server, fromTime, toTime, onChange, className: classNameProp, onTextFilterChange } = this.props;
    const { isFromTimeValid, isToTimeValid } = this.state;

    return (
      <Paper elevation={1} className={classNameProp}>
        <Grid container spacing={16} >
          <Grid item xs={4}>
            <TextField fullWidth
                       error={!isFromTimeValid}
                       name="fromTime"
                       label="From Time"
                       value={fromTime}
                       onChange={onChange}
                       helperText={!isFromTimeValid && "Date is missing or invalid"}
                       onBlur={() => this._validateDate('fromTime', 'isFromTimeValid')}
                       InputLabelProps={{
                         shrink: true,
                       }}
            />
          </Grid>
          <Grid item xs={4}>
            <TextField fullWidth
                       error={!isToTimeValid}
                       name="toTime"
                       label="To Time"
                       value={toTime}
                       onChange={onChange}
                       helperText={!isToTimeValid && "Date is missing or invalid"}
                       onBlur={() => this._validateDate('toTime', 'isToTimeValid')}
                       InputLabelProps={{
                         shrink: true,
                       }}
            />
          </Grid>
          <Grid item xs={4}>
            <TextField
              fullWidth
              label="Serach Criteria"
              name={'textFilter'}
              value={textFilter.text}
              onChange={(e) => onTextFilterChange(e.target.value)}
            />
          </Grid>
          <Grid item xs={4}>
            <TextField fullWidth
                       name="server"
                       label="Server"
                       value={server}
                       onChange={onChange}
                       InputLabelProps={{
                         shrink: true,
                       }}
            />
          </Grid>
          <Grid item xs={4}>
            <TextField
              fullWidth
              name="session"
              label="Session"
              value={session}
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
